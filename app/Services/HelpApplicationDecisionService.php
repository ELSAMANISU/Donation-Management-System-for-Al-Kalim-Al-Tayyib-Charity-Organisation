<?php

namespace App\Services;

use App\Data\DecidedHelpApplication;
use App\Enums\HelpApplicationStatus;
use App\Enums\InternalNotificationAudience;
use App\Enums\InternalNotificationEventType;
use App\Enums\InternalNotificationProjectionState;
use App\Enums\InternalNotificationType;
use App\Enums\UserRole;
use App\Models\HelpApplication;
use App\Models\HelpApplicationDuplicateWarning;
use App\Models\InternalNotificationEvent;
use App\Models\InternalNotificationEventRecipient;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class HelpApplicationDecisionService
{
    private const APPLICATION_FIELDS = [
        'id', 'reference', 'applicant_id', 'status', 'open_slot', 'reviewed_by', 'submitted_at',
        'review_started_at', 'status_changed_at', 'decided_by', 'decided_at', 'decision_note',
        'appeal_eligibility_ended_at', 'category_id', 'category_assigned_by', 'category_assigned_at',
    ];

    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly InternalNotificationEventKey $eventKey,
        private readonly InternalNotificationProjector $projector,
    ) {}

    /** A fresh, locked snapshot prevents mixed reads from exposing an actionable stale form.
     * @return array{ready: bool, unreviewed: bool, confirmed: bool}
     */
    public function readiness(User $actor, string $reference, HelpApplication $snapshot): array
    {
        return DB::transaction(function () use ($actor, $reference, $snapshot): array {
            $application = $this->applicationQuery($actor, $reference)->lockForUpdate()->first();
            $lockedActor = User::query()->select(['id', 'role', 'is_active', 'must_change_password'])
                ->whereKey($actor->getKey())->lockForUpdate()->first();
            if ($application === null || $lockedActor === null
                || ! Gate::forUser($lockedActor)->allows('decide', $application)) {
                return ['ready' => false, 'unreviewed' => false, 'confirmed' => false];
            }

            foreach (['status', 'reviewed_by', 'submitted_at', 'review_started_at', 'category_id', 'category_assigned_by', 'category_assigned_at'] as $field) {
                if ($application->getRawOriginal($field) !== $snapshot->getRawOriginal($field)) {
                    return ['ready' => false, 'unreviewed' => false, 'confirmed' => false];
                }
            }

            return $this->inspect($application);
        });
    }

    public function decide(User $actor, string $reference, string $outcome, string $note): DecidedHelpApplication
    {
        $note = trim($note);
        abort_unless(in_array($outcome, ['approved', 'rejected'], true)
            && mb_strlen($note) >= 10 && mb_strlen($note) <= 2000, 404);

        return DB::transaction(function () use ($actor, $reference, $outcome, $note): DecidedHelpApplication {
            $application = $this->applicationQuery($actor, $reference)->lockForUpdate()->firstOrFail();
            $lockedActor = User::query()->select(['id', 'name', 'role', 'is_active', 'must_change_password'])
                ->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            abort_unless(Gate::forUser($lockedActor)->allows('decide', $application), 404);
            $readiness = $this->inspect($application);
            abort_unless($readiness['ready'] && ! ($outcome === 'approved' && $readiness['confirmed']), 404);

            $timestamp = now()->toImmutable();
            $application->status = HelpApplicationStatus::from($outcome);
            $application->decided_by = $lockedActor->getKey();
            $application->decided_at = $timestamp;
            $application->decision_note = $note;
            $application->status_changed_at = $timestamp;
            $application->updated_by = $lockedActor->getKey();
            $application->updated_at = $timestamp;
            if ($outcome === 'rejected') {
                $application->appeal_eligibility_ended_at = $timestamp->addDays(30);
            }
            $application->timestamps = false;
            $application->save();

            $this->auditLogger->log('help_application.decided', actor: $lockedActor, subject: $application,
                oldValues: ['status' => 'under_review'], newValues: ['status' => $outcome]);

            $eventType = $outcome === 'approved' ? InternalNotificationEventType::HelpApplicationApproved : InternalNotificationEventType::HelpApplicationRejected;
            $event = new InternalNotificationEvent;
            $event->reference = (string) Str::uuid();
            $event->type = $eventType;
            $event->help_application_id = $application->getKey();
            $event->deduplication_key = $this->eventKey->make($eventType, $application->getKey());
            $event->occurred_at = $timestamp;
            $event->projected_at = null;
            $event->save();

            $intent = new InternalNotificationEventRecipient;
            $intent->event_id = $event->getKey();
            $intent->recipient_id = $application->applicant_id;
            $intent->recipient_role = UserRole::User;
            $intent->audience = InternalNotificationAudience::Applicant;
            $intent->notification_type = $outcome === 'approved' ? InternalNotificationType::HelpApplicationApproved : InternalNotificationType::HelpApplicationRejected;
            $intent->state = InternalNotificationProjectionState::Pending;
            $intent->attempts = 0;
            $intent->available_at = $timestamp;
            $intent->save();

            DB::afterCommit(function () use ($event): void {
                try {
                    $this->projector->projectEvent($event->getKey());
                } catch (Throwable) {
                    try {
                        Log::warning('Help Application notification projection could not be completed.');
                    } catch (Throwable) {
                    }
                }
            });

            return new DecidedHelpApplication($outcome);
        });
    }

    private function applicationQuery(User $actor, string $reference): Builder
    {
        return HelpApplication::query()->select(self::APPLICATION_FIELDS)
            ->where('reference', $reference)->where('status', HelpApplicationStatus::UnderReview)
            ->when(! $actor->hasRole(UserRole::SuperAdmin), fn ($query) => $query->where('reviewed_by', $actor->getKey()));
    }

    /** @return array{ready: bool, unreviewed: bool, confirmed: bool} */
    private function inspect(HelpApplication $application): array
    {
        $invalid = ['ready' => false, 'unreviewed' => false, 'confirmed' => false];
        if ($application->getRawOriginal('status') !== 'under_review'
            || $application->open_slot !== true || $application->submitted_at === null
            || $application->review_started_at === null || $application->status_changed_at === null
            || ! $application->review_started_at->equalTo($application->status_changed_at)
            || $application->decided_by !== null || $application->decided_at !== null
            || $application->getRawOriginal('decision_note') !== null
            || $application->appeal_eligibility_ended_at !== null
            || $application->category_id === null || $application->category_assigned_by === null
            || $application->category_assigned_at === null) {
            return $invalid;
        }

        $unreviewed = $confirmed = false;
        $warnings = HelpApplicationDuplicateWarning::query()->select([
            'id', 'submitted_application_id', 'matched_application_id', 'status',
            'resolved_by', 'resolved_at', 'resolution_note',
        ])->where('submitted_application_id', $application->getKey())->orderBy('id')->lockForUpdate()->get();
        foreach ($warnings as $warning) {
            if ($warning->submitted_application_id === $warning->matched_application_id) {
                return $invalid;
            }
            $status = $warning->getRawOriginal('status');
            if ($status === 'unreviewed') {
                if ($warning->resolved_by !== null || $warning->resolved_at !== null || $warning->getRawOriginal('resolution_note') !== null) {
                    return $invalid;
                }
                $unreviewed = true;
            } elseif (in_array($status, ['confirmed_match', 'dismissed'], true)) {
                if ($warning->resolved_by === null || $warning->resolved_at === null || $warning->getRawOriginal('resolution_note') === null) {
                    return $invalid;
                }
                $confirmed = $confirmed || $status === 'confirmed_match';
            } else {
                return $invalid;
            }
        }

        return ['ready' => ! $unreviewed, 'unreviewed' => $unreviewed, 'confirmed' => $confirmed];
    }
}
