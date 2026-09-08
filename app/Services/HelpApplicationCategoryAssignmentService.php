<?php

namespace App\Services;

use App\Data\AssignedHelpApplicationCategory;
use App\Enums\HelpApplicationStatus;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\HelpApplication;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class HelpApplicationCategoryAssignmentService
{
    private const UNAVAILABLE = 'The selected category is unavailable. / الفئة المحددة غير متاحة.';

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function assign(User $actor, string $reference, string $categorySlug): AssignedHelpApplicationCategory
    {
        return DB::transaction(function () use ($actor, $reference, $categorySlug): AssignedHelpApplicationCategory {
            $application = HelpApplication::query()->select([
                'id', 'reference', 'status', 'open_slot', 'category_id', 'category_assigned_by',
                'category_assigned_at', 'reviewed_by', 'review_started_at', 'decided_by',
                'decided_at', 'submitted_at', 'status_changed_at', 'appeal_eligibility_ended_at',
                'updated_by', 'updated_at',
            ])->where('reference', $reference)
                ->where('status', HelpApplicationStatus::UnderReview)
                ->when(! $actor->hasRole(UserRole::SuperAdmin), fn ($query) => $query->where('reviewed_by', $actor->getKey()))
                ->lockForUpdate()->firstOrFail();

            $lockedActor = User::query()->select(['id', 'name', 'role', 'is_active', 'must_change_password'])
                ->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();

            if (! Gate::forUser($lockedActor)->allows('assignCategory', $application)) {
                throw (new ModelNotFoundException)->setModel(HelpApplication::class);
            }
            $this->ensureValidLifecycle($application);

            if ($application->category_id !== null) {
                $this->ensureCoherentExistingAssignment($application);

                return new AssignedHelpApplicationCategory(false);
            }

            $this->ensureUnassigned($application);
            $category = Category::query()->select(['id', 'slug'])->active()
                ->where('slug', $categorySlug)->lockForUpdate()->first();
            if ($category === null || Category::normalizeSlug($categorySlug) !== $categorySlug) {
                throw ValidationException::withMessages(['category' => self::UNAVAILABLE]);
            }

            $transitionedAt = now();
            $application->category_id = $category->getKey();
            $application->category_assigned_by = $lockedActor->getKey();
            $application->category_assigned_at = $transitionedAt;
            $application->updated_by = $lockedActor->getKey();
            $application->updated_at = $transitionedAt;
            $application->timestamps = false;
            $application->save();

            $this->auditLogger->log(
                'help_application.category_assigned', actor: $lockedActor, subject: $application,
                oldValues: ['status' => 'under_review', 'open_slot' => true, 'category_slug' => null],
                newValues: ['status' => 'under_review', 'open_slot' => true, 'category_slug' => $category->slug],
            );

            return new AssignedHelpApplicationCategory(true);
        });
    }

    private function ensureValidLifecycle(HelpApplication $application): void
    {
        $valid = $application->status === HelpApplicationStatus::UnderReview
            && $application->open_slot === true
            && $application->review_started_at !== null
            && $application->status_changed_at !== null
            && $application->review_started_at->equalTo($application->status_changed_at)
            && $application->submitted_at !== null
            && $application->decided_by === null
            && $application->decided_at === null
            && $application->appeal_eligibility_ended_at === null;

        if (! $valid) {
            throw (new ModelNotFoundException)->setModel(HelpApplication::class);
        }
    }

    private function ensureUnassigned(HelpApplication $application): void
    {
        $reviewerStateIsValid = $application->reviewed_by === null
            ? $application->updated_by === null
            : $application->updated_by === $application->reviewed_by;

        if ($application->category_id !== null
            || $application->category_assigned_by !== null
            || $application->category_assigned_at !== null
            || ! $reviewerStateIsValid) {
            throw (new ModelNotFoundException)->setModel(HelpApplication::class);
        }
    }

    private function ensureCoherentExistingAssignment(HelpApplication $application): void
    {
        if ($application->category_assigned_at === null) {
            throw (new ModelNotFoundException)->setModel(HelpApplication::class);
        }
    }
}
