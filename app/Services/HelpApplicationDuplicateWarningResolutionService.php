<?php

namespace App\Services;

use App\Data\ResolvedHelpApplicationDuplicateWarning;
use App\Enums\HelpApplicationDuplicateWarningStatus;
use App\Enums\HelpApplicationStatus;
use App\Enums\UserRole;
use App\Models\HelpApplication;
use App\Models\HelpApplicationDuplicateWarning;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class HelpApplicationDuplicateWarningResolutionService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function resolve(User $actor, string $reference, string $warningReference, string $outcome, string $note): ResolvedHelpApplicationDuplicateWarning
    {
        $note = trim($note);
        abort_unless(in_array($outcome, ['confirmed_match', 'dismissed'], true)
            && mb_strlen($note) >= 10 && mb_strlen($note) <= 1000, 404);

        return DB::transaction(function () use ($actor, $reference, $warningReference, $outcome, $note) {
            $application = HelpApplication::query()->select([
                'id', 'reference', 'status', 'open_slot', 'reviewed_by', 'submitted_at',
                'review_started_at', 'status_changed_at', 'decided_by', 'decided_at', 'appeal_eligibility_ended_at',
            ])->where('reference', $reference)->where('status', HelpApplicationStatus::UnderReview)
                ->when(! $actor->hasRole(UserRole::SuperAdmin), fn ($query) => $query->where('reviewed_by', $actor->getKey()))
                ->lockForUpdate()->firstOrFail();
            $lockedActor = User::query()->select(['id', 'role', 'is_active', 'must_change_password'])
                ->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            abort_unless(Gate::forUser($lockedActor)->allows('resolveDuplicateWarning', $application), 404);
            abort_unless($application->open_slot === true && $application->submitted_at !== null
                && $application->review_started_at !== null && $application->status_changed_at !== null
                && $application->review_started_at->equalTo($application->status_changed_at)
                && $application->decided_by === null && $application->decided_at === null
                && $application->appeal_eligibility_ended_at === null, 404);

            $warning = HelpApplicationDuplicateWarning::query()->select([
                'id', 'submitted_application_id', 'matched_application_id', 'status',
                'resolved_by', 'resolved_at', 'resolution_note',
            ])->where('reference', $warningReference)->where('submitted_application_id', $application->getKey())
                ->lockForUpdate()->firstOrFail();
            abort_if($warning->submitted_application_id === $warning->matched_application_id, 404);
            $status = $warning->getRawOriginal('status');
            if (in_array($status, ['confirmed_match', 'dismissed'], true)) {
                abort_unless($warning->resolved_at !== null && $warning->getRawOriginal('resolution_note') !== null, 404);

                return new ResolvedHelpApplicationDuplicateWarning(false);
            }
            abort_unless($status === 'unreviewed' && $warning->resolved_by === null
                && $warning->resolved_at === null && $warning->getRawOriginal('resolution_note') === null, 404);
            $timestamp = now();
            $warning->status = HelpApplicationDuplicateWarningStatus::from($outcome);
            $warning->resolved_by = $lockedActor->getKey();
            $warning->resolved_at = $timestamp;
            $warning->resolution_note = $note;
            $warning->updated_at = $timestamp;
            $warning->timestamps = false;
            $warning->save();
            $this->auditLogger->log('help_application.duplicate_warning_resolved', actor: $lockedActor, subject: $warning,
                oldValues: ['status' => 'unreviewed'], newValues: ['status' => $outcome]);

            return new ResolvedHelpApplicationDuplicateWarning(true);
        });
    }
}
