<?php

namespace App\Services;

use App\Models\Category;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CategoryLifecycleService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function delete(User $actor, Category $category, Request $request): Category
    {
        return DB::transaction(function () use ($actor, $category, $request): Category {
            $lockedActor = User::query()->lockForUpdate()->findOrFail($actor->getKey());
            $lockedCategory = Category::query()->lockForUpdate()->findOrFail($category->getKey());

            Gate::forUser($lockedActor)->authorize('delete', $lockedCategory);

            if (! $lockedActor->is_active) {
                abort(403);
            }

            $wasDeleted = $lockedCategory->trashed();
            $oldValues = $this->lifecycleState($lockedCategory, $wasDeleted);
            $lockedCategory->updated_by = $lockedActor->getKey();
            $lockedCategory->save();
            $lockedCategory->delete();

            $this->auditLogger->log(
                action: 'category.deleted',
                actor: $lockedActor,
                subject: $lockedCategory,
                oldValues: $oldValues,
                newValues: $this->lifecycleState($lockedCategory, $wasDeleted),
                request: $request,
            );

            return $lockedCategory;
        });
    }

    public function restore(User $actor, int $categoryId, Request $request): Category
    {
        return DB::transaction(function () use ($actor, $categoryId, $request): Category {
            $lockedActor = User::query()->lockForUpdate()->findOrFail($actor->getKey());
            $lockedCategory = Category::onlyTrashed()->lockForUpdate()->findOrFail($categoryId);

            Gate::forUser($lockedActor)->authorize('restore', $lockedCategory);

            if (! $lockedActor->is_active) {
                abort(403);
            }

            $wasDeleted = $lockedCategory->trashed();
            $oldValues = $this->lifecycleState($lockedCategory, $wasDeleted);
            $lockedCategory->restore();
            $lockedCategory->updated_by = $lockedActor->getKey();
            $lockedCategory->save();

            $this->auditLogger->log(
                action: 'category.restored',
                actor: $lockedActor,
                subject: $lockedCategory,
                oldValues: $oldValues,
                newValues: $this->lifecycleState($lockedCategory, $wasDeleted),
                request: $request,
            );

            return $lockedCategory;
        });
    }

    /** @return array{was_deleted: bool, is_deleted: bool, is_active: bool, display_order: int} */
    private function lifecycleState(Category $category, bool $wasDeleted): array
    {
        return [
            'was_deleted' => $wasDeleted,
            'is_deleted' => $category->trashed(),
            'is_active' => $category->is_active,
            'display_order' => $category->display_order,
        ];
    }
}
