<?php

namespace App\Services;

use App\Data\UpdatedHelpApplicationDraft;
use App\Enums\HelpApplicationStatus;
use App\Enums\IdentityDocumentType;
use App\Enums\UserRole;
use App\Models\HelpApplication;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HelpApplicationDraftService
{
    /** @var list<string> */
    private const EDITABLE_FIELDS = [
        'full_name', 'email', 'phone', 'address', 'date_of_birth',
        'identity_document_type', 'identity_issuing_country', 'identity_document_number',
        'requested_amount', 'private_story', 'preferred_receiving_method',
        'public_identity_preference',
    ];

    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly IdentityBlindIndex $blindIndex,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function create(User $actor, array $attributes, Request $request): HelpApplication
    {
        try {
            return DB::transaction(function () use ($actor, $attributes, $request): HelpApplication {
                $lockedActor = User::query()->lockForUpdate()->findOrFail($actor->getKey());
                Gate::forUser($lockedActor)->authorize('create', HelpApplication::class);
                $this->assertEligibleActor($lockedActor);

                if (HelpApplication::query()->forApplicant($lockedActor)->where('open_slot', true)->lockForUpdate()->exists()) {
                    $this->throwOpenApplicationValidation();
                }

                $application = new HelpApplication;
                $application->reference = (string) Str::uuid();
                $application->applicant_id = $lockedActor->getKey();
                $application->category_id = null;
                $application->status = HelpApplicationStatus::Draft;
                $application->open_slot = true;

                foreach (self::EDITABLE_FIELDS as $field) {
                    $application->{$field} = $attributes[$field] ?? null;
                }

                $this->setBlindIndex($application);

                foreach ([
                    'consent_version', 'consented_at', 'category_assigned_by', 'category_assigned_at',
                    'reviewed_by', 'review_started_at', 'decided_by', 'decided_at', 'submitted_at',
                    'status_changed_at', 'appeal_eligibility_ended_at',
                ] as $field) {
                    $application->{$field} = null;
                }

                $application->updated_by = $lockedActor->getKey();
                $application->save();

                $this->auditLogger->log(
                    action: 'help_application.draft_created',
                    actor: $lockedActor,
                    subject: $application,
                    newValues: ['status' => 'draft', 'open_slot' => true],
                    request: $request,
                );

                return $application;
            });
        } catch (QueryException $exception) {
            if ($this->isOpenApplicationUniqueViolation($exception)) {
                $this->throwOpenApplicationValidation();
            }

            throw $exception;
        }
    }

    /** @param array<string, mixed> $attributes */
    public function update(
        User $actor,
        HelpApplication $application,
        array $attributes,
        bool $clearIdentityDocumentNumber,
        Request $request,
    ): UpdatedHelpApplicationDraft {
        return DB::transaction(function () use ($actor, $application, $attributes, $clearIdentityDocumentNumber, $request): UpdatedHelpApplicationDraft {
            $lockedActor = User::query()->lockForUpdate()->findOrFail($actor->getKey());
            $lockedApplication = HelpApplication::query()->lockForUpdate()->findOrFail($application->getKey());
            Gate::forUser($lockedActor)->authorize('update', $lockedApplication);
            $this->assertEligibleActor($lockedActor);

            if ($lockedApplication->applicant_id !== $lockedActor->getKey()
                || $lockedApplication->status !== HelpApplicationStatus::Draft
                || $lockedApplication->open_slot !== true) {
                abort(403);
            }

            foreach (self::EDITABLE_FIELDS as $field) {
                if ($field === 'identity_document_number') {
                    continue;
                }

                $this->setWhenChanged($lockedApplication, $field, $attributes[$field] ?? null);
            }

            $replacement = $attributes['identity_document_number'] ?? null;
            $finalIdentityNumber = $clearIdentityDocumentNumber
                ? null
                : ($replacement ?? $lockedApplication->identity_document_number);
            $this->setWhenChanged($lockedApplication, 'identity_document_number', $finalIdentityNumber);
            $this->setBlindIndex($lockedApplication);

            $changedFields = array_intersect(array_keys($lockedApplication->getDirty()), [
                ...self::EDITABLE_FIELDS,
                'identity_blind_index',
                'identity_blind_index_version',
            ]);

            if ($changedFields === []) {
                return new UpdatedHelpApplicationDraft($lockedApplication, false);
            }

            $lockedApplication->updated_by = $lockedActor->getKey();
            $lockedApplication->save();
            $this->auditLogger->log(
                action: 'help_application.draft_updated',
                actor: $lockedActor,
                subject: $lockedApplication,
                oldValues: ['status' => 'draft'],
                newValues: ['status' => 'draft'],
                request: $request,
            );

            return new UpdatedHelpApplicationDraft($lockedApplication, true);
        });
    }

    private function assertEligibleActor(User $actor): void
    {
        if (! $actor->is_active || $actor->must_change_password || ! $actor->hasRole(UserRole::User)) {
            abort(403);
        }
    }

    private function setBlindIndex(HelpApplication $application): void
    {
        $type = $application->identity_document_type;
        $country = $application->identity_issuing_country;
        $number = $application->identity_document_number;

        if (! $type instanceof IdentityDocumentType || ! is_string($country) || ! is_string($number)) {
            $this->setWhenChanged($application, 'identity_blind_index', null);
            $this->setWhenChanged($application, 'identity_blind_index_version', null);

            return;
        }

        $version = $this->blindIndex->currentKeyVersion();
        $digest = $this->blindIndex->compute($type, $country, $number, $version);
        $this->setWhenChanged($application, 'identity_blind_index', $digest);
        $this->setWhenChanged($application, 'identity_blind_index_version', $version);
    }

    private function setWhenChanged(HelpApplication $application, string $field, mixed $desired): void
    {
        $current = $application->{$field};
        $currentComparable = $current instanceof \BackedEnum ? $current->value : $current;
        $desiredComparable = $desired instanceof \BackedEnum ? $desired->value : $desired;

        if ($currentComparable !== $desiredComparable) {
            $application->{$field} = $desired;
        }
    }

    private function isOpenApplicationUniqueViolation(QueryException $exception): bool
    {
        $state = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $code = (int) ($exception->errorInfo[1] ?? 0);
        $diagnostic = (string) ($exception->errorInfo[2] ?? '');

        if ($state === '23000' && $code === 1062) {
            return preg_match('/\bfor key\s+[\'"`](?:[^\'"`]+\.)?help_applications_applicant_open_unique[\'"`]/i', $diagnostic) === 1;
        }

        return in_array($state, ['23000', '23505'], true)
            && $code === 19
            && preg_match('/unique constraint failed:\s*[`"\[]?help_applications[`"\]]?\.[`"\[]?applicant_id[`"\]]?\s*,\s*[`"\[]?help_applications[`"\]]?\.[`"\[]?open_slot[`"\]]?/i', $diagnostic) === 1;
    }

    private function throwOpenApplicationValidation(): never
    {
        throw ValidationException::withMessages([
            'help_application' => 'You already have an open Help Application. Continue with it before starting another. / لديك طلب مساعدة مفتوح بالفعل. تابع ذلك الطلب قبل بدء طلب جديد.',
        ]);
    }
}
