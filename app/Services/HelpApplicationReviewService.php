<?php

namespace App\Services;

use App\Data\StartedHelpApplicationReview;
use App\Enums\HelpApplicationStatus;
use App\Models\HelpApplication;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class HelpApplicationReviewService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function start(User $actor, string $reference): StartedHelpApplicationReview
    {
        return DB::transaction(function () use ($actor, $reference): StartedHelpApplicationReview {
            $application = HelpApplication::query()->select([
                'id', 'reference', 'status', 'open_slot', 'category_id', 'category_assigned_by',
                'category_assigned_at', 'reviewed_by', 'review_started_at', 'decided_by',
                'decided_at', 'submitted_at', 'status_changed_at', 'appeal_eligibility_ended_at', 'updated_by',
            ])->where('reference', $reference)->lockForUpdate()->firstOrFail();

            $lockedActor = User::query()
                ->select(['id', 'name', 'role', 'is_active', 'must_change_password'])
                ->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();

            Gate::forUser($lockedActor)->authorize('startReview', $application);

            if ($application->status === HelpApplicationStatus::UnderReview) {
                $this->ensureValidStartedState($application);

                return new StartedHelpApplicationReview(false);
            }

            $this->ensureValidPendingState($application);
            $transitionedAt = now();
            $application->status = HelpApplicationStatus::UnderReview;
            $application->reviewed_by = $lockedActor->getKey();
            $application->review_started_at = $transitionedAt;
            $application->status_changed_at = $transitionedAt;
            $application->updated_by = $lockedActor->getKey();
            $application->timestamps = false;
            $application->save();

            $this->auditLogger->log(
                'help_application.review_started', actor: $lockedActor, subject: $application,
                oldValues: ['status' => 'pending', 'open_slot' => true],
                newValues: ['status' => 'under_review', 'open_slot' => true],
            );

            return new StartedHelpApplicationReview(true);
        });
    }

    private function ensureValidPendingState(HelpApplication $application): void
    {
        $valid = $application->status === HelpApplicationStatus::Pending
            && $application->open_slot === true && $application->category_id === null
            && $application->category_assigned_by === null && $application->category_assigned_at === null
            && $application->reviewed_by === null && $application->review_started_at === null
            && $application->decided_by === null && $application->decided_at === null
            && $application->submitted_at !== null && $application->status_changed_at !== null
            && $application->status_changed_at->equalTo($application->submitted_at)
            && $application->appeal_eligibility_ended_at === null;

        if (! $valid) {
            throw (new ModelNotFoundException)->setModel(HelpApplication::class);
        }
    }

    private function ensureValidStartedState(HelpApplication $application): void
    {
        $valid = $application->open_slot === true && $application->category_id === null
            && $application->category_assigned_by === null && $application->category_assigned_at === null
            && $application->reviewed_by !== null && $application->review_started_at !== null
            && $application->status_changed_at !== null
            && $application->review_started_at->equalTo($application->status_changed_at)
            && $application->updated_by === $application->reviewed_by
            && $application->decided_by === null && $application->decided_at === null
            && $application->submitted_at !== null && $application->appeal_eligibility_ended_at === null;

        if (! $valid) {
            throw (new ModelNotFoundException)->setModel(HelpApplication::class);
        }
    }
}
