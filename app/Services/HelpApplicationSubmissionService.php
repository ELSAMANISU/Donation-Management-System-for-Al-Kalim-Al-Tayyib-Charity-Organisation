<?php

namespace App\Services;

use App\Enums\HelpApplicationDuplicateWarningStatus;
use App\Enums\HelpApplicationStatus;
use App\Enums\IdentityDocumentType;
use App\Enums\InternalNotificationAudience;
use App\Enums\InternalNotificationEventType;
use App\Enums\InternalNotificationProjectionState;
use App\Enums\InternalNotificationType;
use App\Enums\PublicIdentityPreference;
use App\Models\HelpApplication;
use App\Models\HelpApplicationDocument;
use App\Models\HelpApplicationDuplicateWarning;
use App\Models\InternalNotificationEvent;
use App\Models\InternalNotificationEventRecipient;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

final class HelpApplicationSubmissionService
{
    public function __construct(
        private readonly IdentityBlindIndex $blindIndex,
        private readonly AuditLogger $auditLogger,
        private readonly InternalNotificationRecipientSelector $recipientSelector,
        private readonly InternalNotificationEventKey $eventKey,
        private readonly InternalNotificationProjector $projector,
    ) {}

    public function submit(User $actor, HelpApplication $application, bool $consentAccepted, Request $request): bool
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Help Application submission requires a top-level transaction.');
        }

        $eventId = null;
        $submitted = DB::transaction(function () use ($actor, $application, $consentAccepted, $request, &$eventId): bool {
            $locked = HelpApplication::query()->lockForUpdate()->findOrFail($application->getKey());
            $freshActor = User::query()->lockForUpdate()->findOrFail($actor->getKey());
            Gate::forUser($freshActor)->authorize('submit', $locked);

            if ($locked->status !== HelpApplicationStatus::Draft) {
                return false;
            }

            if ($locked->open_slot !== true || $locked->category_id !== null) {
                $this->reject();
            }

            $safeErrors = $this->safeSubmissionErrors($locked, $consentAccepted);
            $this->validateDocumentEligibilityConfiguration();

            $documents = HelpApplicationDocument::query()
                ->forApplication($locked)
                ->submissionEligibleMetadata()
                ->inUploadOrder()
                ->lockForUpdate()
                ->get();

            if ($documents->isEmpty()) {
                $safeErrors['document'] = 'At least one eligible private supporting document is required before submission. / يجب رفع مستند داعم خاص مؤهل واحد على الأقل قبل إرسال الطلب.';
            }

            if ($safeErrors !== []) {
                throw ValidationException::withMessages($safeErrors);
            }

            $versions = $this->validatedIdentityVersions();
            $currentVersion = $this->blindIndex->currentKeyVersion();
            $this->verifyCurrentIdentity($locked, $currentVersion);

            if (! $this->hasVerifiedDocument($locked, $documents->all())) {
                $this->reject();
            }

            $matches = $this->matchingApplicationIds($locked, $versions);
            $transitionAt = now()->toImmutable();

            foreach ($matches as $matchedId) {
                if (! HelpApplicationDuplicateWarning::query()
                    ->where('submitted_application_id', $locked->getKey())
                    ->where('matched_application_id', $matchedId)->exists()) {
                    $warning = new HelpApplicationDuplicateWarning;
                    $warning->reference = (string) Str::uuid();
                    $warning->submitted_application_id = $locked->getKey();
                    $warning->matched_application_id = $matchedId;
                    $warning->status = HelpApplicationDuplicateWarningStatus::Unreviewed;
                    $warning->save();
                }
            }

            $locked->status = HelpApplicationStatus::Pending;
            $locked->submitted_at = $transitionAt;
            $locked->status_changed_at = $transitionAt;
            $locked->updated_by = $freshActor->getKey();
            $locked->consent_version = HelpApplication::CONSENT_VERSION;
            $locked->consented_at = $transitionAt;
            $locked->save();

            $this->auditLogger->log(
                'help_application.submitted',
                $freshActor,
                $locked,
                ['status' => 'draft', 'open_slot' => true],
                ['status' => 'pending', 'open_slot' => true],
                $request,
            );

            $event = new InternalNotificationEvent;
            $event->reference = (string) Str::uuid();
            $event->type = InternalNotificationEventType::HelpApplicationSubmitted;
            $event->help_application_id = $locked->getKey();
            $event->deduplication_key = $this->eventKey->make(InternalNotificationEventType::HelpApplicationSubmitted, $locked->getKey());
            $event->occurred_at = $transitionAt;
            $event->projected_at = null;
            $event->save();

            $this->createIntent($event, $freshActor, InternalNotificationAudience::Applicant, InternalNotificationType::HelpApplicationSubmissionConfirmation, $transitionAt);
            foreach ($this->recipientSelector->lockedEligibleAdministrators() as $administrator) {
                $this->createIntent($event, $administrator, InternalNotificationAudience::Administrator, InternalNotificationType::HelpApplicationNewSubmission, $transitionAt);
            }

            $eventId = $event->getKey();

            return true;
        });

        if ($submitted && $eventId !== null) {
            try {
                $result = $this->projector->projectEvent($eventId);

                if ($result->failed > 0) {
                    $this->warnProjectionFailure();
                }
            } catch (Throwable) {
                $this->warnProjectionFailure();
            }
        }

        return $submitted;
    }

    /** @return array<string, string> */
    private function safeSubmissionErrors(HelpApplication $application, bool $consentAccepted): array
    {
        $data = [];
        foreach (['full_name', 'email', 'phone', 'address', 'date_of_birth', 'identity_document_type', 'identity_issuing_country', 'identity_document_number', 'requested_amount', 'private_story', 'preferred_receiving_method', 'public_identity_preference'] as $field) {
            try {
                $value = $application->{$field};
                $data[$field] = $value instanceof \BackedEnum ? $value->value : $value;
            } catch (Throwable) {
                $this->reject();
            }
        }

        $validator = Validator::make($data, [
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'address' => ['required', 'string', 'max:2000'],
            'date_of_birth' => ['required', 'string', 'date_format:Y-m-d', 'before_or_equal:today'],
            'identity_document_type' => ['required', Rule::enum(IdentityDocumentType::class)],
            'identity_issuing_country' => ['required', 'string', 'size:2', 'regex:/\A[A-Z]{2}\z/'],
            'identity_document_number' => ['required', 'string', 'max:255'],
            'requested_amount' => ['required', 'regex:/\A(?:0|[1-9][0-9]{0,15})\.[0-9]{2}\z/', 'not_in:0.00'],
            'private_story' => ['required', 'string', 'max:20000'],
            'preferred_receiving_method' => ['required', 'string', 'max:2000'],
            'public_identity_preference' => ['required', Rule::enum(PublicIdentityPreference::class)],
        ], [
            'full_name.required' => 'Full name is required before submission. / يجب إدخال الاسم الكامل قبل إرسال الطلب.',
            'full_name.*' => 'Enter a valid full name before submission. / أدخل اسماً كاملاً صالحاً قبل إرسال الطلب.',
            'email.required' => 'Email is required before submission. / يجب إدخال البريد الإلكتروني قبل إرسال الطلب.',
            'email.*' => 'Enter a valid email address before submission. / أدخل عنوان بريد إلكتروني صالحاً قبل إرسال الطلب.',
            'phone.required' => 'Phone is required before submission. / يجب إدخال رقم الهاتف قبل إرسال الطلب.',
            'phone.*' => 'Enter a valid phone value before submission. / أدخل قيمة هاتف صالحة قبل إرسال الطلب.',
            'address.required' => 'Address is required before submission. / يجب إدخال العنوان قبل إرسال الطلب.',
            'address.*' => 'Enter a valid address before submission. / أدخل عنواناً صالحاً قبل إرسال الطلب.',
            'date_of_birth.required' => 'Date of birth is required before submission. / يجب إدخال تاريخ الميلاد قبل إرسال الطلب.',
            'date_of_birth.*' => 'Enter a valid date of birth before submission. / أدخل تاريخ ميلاد صالحاً قبل إرسال الطلب.',
            'identity_document_type.required' => 'Identity document type is required before submission. / يجب اختيار نوع وثيقة الهوية قبل إرسال الطلب.',
            'identity_document_type.*' => 'Choose a valid identity document type before submission. / اختر نوع وثيقة هوية صالحاً قبل إرسال الطلب.',
            'identity_issuing_country.required' => 'Identity issuing country is required before submission. / يجب إدخال بلد إصدار الهوية قبل إرسال الطلب.',
            'identity_issuing_country.*' => 'Enter a valid two-letter issuing-country code before submission. / أدخل رمز بلد إصدار صالحاً من حرفين قبل إرسال الطلب.',
            'identity_document_number.required' => 'Identity document number is required before submission. / يجب إدخال رقم وثيقة الهوية قبل إرسال الطلب.',
            'identity_document_number.*' => 'Enter a valid identity document number before submission. / أدخل رقم وثيقة هوية صالحاً قبل إرسال الطلب.',
            'requested_amount.required' => 'Requested amount is required before submission. / يجب إدخال المبلغ المطلوب قبل إرسال الطلب.',
            'requested_amount.*' => 'Enter a valid requested amount before submission. / أدخل مبلغاً مطلوباً صالحاً قبل إرسال الطلب.',
            'private_story.required' => 'Private story is required before submission. / يجب إدخال القصة الخاصة قبل إرسال الطلب.',
            'private_story.*' => 'Enter a valid private story before submission. / أدخل قصة خاصة صالحة قبل إرسال الطلب.',
            'preferred_receiving_method.required' => 'Preferred receiving method is required before submission. / يجب إدخال طريقة الاستلام المفضلة قبل إرسال الطلب.',
            'preferred_receiving_method.*' => 'Enter a valid preferred receiving method before submission. / أدخل طريقة استلام مفضلة صالحة قبل إرسال الطلب.',
            'public_identity_preference.required' => 'Public identity preference is required before submission. / يجب اختيار تفضيل الهوية العامة قبل إرسال الطلب.',
            'public_identity_preference.*' => 'Choose a valid public identity preference before submission. / اختر تفضيل هوية عامة صالحاً قبل إرسال الطلب.',
        ]);

        $errors = [];
        foreach ($validator->errors()->messages() as $field => $messages) {
            $errors[$field] = $messages[0];
        }

        if (! $consentAccepted) {
            $errors['consent'] = 'You must deliberately accept the consent before submission. / يجب أن توافق صراحةً على الإقرار قبل الإرسال.';
        }

        return $errors;
    }

    private function validateDocumentEligibilityConfiguration(): void
    {
        $configured = config('help_application_documents.submission_eligible_security_statuses');
        $allowed = ['accepted_unscanned', 'clean'];

        if (! is_array($configured) || ! array_is_list($configured) || $configured === []) {
            $this->reject();
        }

        foreach ($configured as $status) {
            if (! is_string($status) || ! in_array($status, $allowed, true)) {
                $this->reject();
            }
        }
    }

    /** @return list<int> */
    private function validatedIdentityVersions(): array
    {
        try {
            $versions = $this->blindIndex->configuredKeyVersions();
        } catch (Throwable) {
            $this->reject();
        }
        $stored = DB::table('help_applications')->whereNotNull('identity_blind_index_version')
            ->distinct()->pluck('identity_blind_index_version')->map(fn ($version): int => (int) $version)->all();

        foreach ($stored as $version) {
            if (! in_array($version, $versions, true)) {
                $this->reject();
            }
        }

        return $versions;
    }

    private function verifyCurrentIdentity(HelpApplication $application, int $currentVersion): void
    {
        if ($application->identity_blind_index_version !== $currentVersion) {
            $this->reject();
        }

        try {
            $computed = $this->blindIndex->compute(
                $application->identity_document_type,
                $application->identity_issuing_country,
                $application->identity_document_number,
                $currentVersion,
            );
        } catch (Throwable) {
            $this->reject();
        }

        if (! is_string($application->identity_blind_index)
            || preg_match('/\A[0-9a-f]{64}\z/', $application->identity_blind_index) !== 1
            || ! hash_equals($application->identity_blind_index, $computed)) {
            $this->reject();
        }
    }

    /** @param list<HelpApplicationDocument> $documents */
    private function hasVerifiedDocument(HelpApplication $application, array $documents): bool
    {
        foreach ($documents as $document) {
            try {
                if ($document->purpose === null
                    || ! HelpApplicationDocumentPath::isOwnedBy($document->storage_path, $application->reference, $document->reference, $document->extension)
                    || $document->checksum_algorithm !== 'sha256'
                    || ! is_int($document->size_bytes) || $document->size_bytes < 1
                    || ! is_string($document->checksum) || preg_match('/\A[0-9a-f]{64}\z/', $document->checksum) !== 1) {
                    continue;
                }

                $stream = Storage::disk(config('help_application_documents.disk'))->readStream($document->storage_path);
                if (! is_resource($stream)) {
                    continue;
                }

                $hash = hash_init('sha256');
                $size = 0;
                try {
                    while (! feof($stream)) {
                        $chunk = fread($stream, 8192);
                        if ($chunk === false) {
                            continue 2;
                        }
                        $size += strlen($chunk);
                        if ($size > $document->size_bytes) {
                            continue 2;
                        }
                        hash_update($hash, $chunk);
                    }
                } finally {
                    fclose($stream);
                }

                if ($size === $document->size_bytes && hash_equals($document->checksum, hash_final($hash))) {
                    return true;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return false;
    }

    /** @param list<int> $versions @return list<int> */
    private function matchingApplicationIds(HelpApplication $application, array $versions): array
    {
        $query = HelpApplication::query()->whereKeyNot($application->getKey())->where(function ($query) use ($application, $versions): void {
            foreach ($versions as $version) {
                $digest = $this->blindIndex->compute($application->identity_document_type, $application->identity_issuing_country, $application->identity_document_number, $version);
                $query->orWhere(fn ($pair) => $pair->where('identity_blind_index_version', $version)->where('identity_blind_index', $digest));
            }
        });

        return $query->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    private function createIntent(InternalNotificationEvent $event, User $recipient, InternalNotificationAudience $audience, InternalNotificationType $type, mixed $at): void
    {
        $intent = new InternalNotificationEventRecipient;
        $intent->event_id = $event->getKey();
        $intent->recipient_id = $recipient->getKey();
        $intent->recipient_role = $recipient->role;
        $intent->audience = $audience;
        $intent->notification_type = $type;
        $intent->state = InternalNotificationProjectionState::Pending;
        $intent->attempts = 0;
        $intent->available_at = $at;
        $intent->save();
    }

    private function reject(): never
    {
        throw ValidationException::withMessages([
            'application' => 'This Help Application is not ready to submit. Review every required field and supporting document, then try again. / طلب المساعدة غير جاهز للإرسال. راجع جميع الحقول والمستند الداعم ثم حاول مرة أخرى.',
        ]);
    }

    private function warnProjectionFailure(): void
    {
        try {
            Log::warning('Help Application notification projection could not be completed.');
        } catch (Throwable) {
        }
    }
}
