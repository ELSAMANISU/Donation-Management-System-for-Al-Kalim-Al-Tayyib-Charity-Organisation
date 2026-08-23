<?php

namespace App\Services;

use App\Models\Category;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CategoryUpdateService
{
    /** @var list<string> */
    private const ACCEPTED_FIELDS = [
        'name_ar',
        'name_en',
        'slug',
        'description_ar',
        'description_en',
        'display_order',
        'is_active',
    ];

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array{name_ar: string, name_en: string, slug: string, description_ar?: string|null, description_en?: string|null, display_order: int, is_active: bool}  $attributes
     */
    public function update(User $actor, Category $category, array $attributes, Request $request): Category
    {
        $normalizedSlug = Category::normalizeSlug($attributes['slug']);

        try {
            return DB::transaction(function () use ($actor, $category, $attributes, $normalizedSlug, $request): Category {
                $lockedActor = User::query()->lockForUpdate()->findOrFail($actor->getKey());
                $lockedCategory = Category::query()->lockForUpdate()->findOrFail($category->getKey());

                Gate::forUser($lockedActor)->authorize('update', $lockedCategory);

                if (! $lockedActor->is_active) {
                    abort(403);
                }

                if (Category::withTrashed()
                    ->where('slug', $normalizedSlug)
                    ->whereKeyNot($lockedCategory->getKey())
                    ->exists()) {
                    $this->throwDuplicateSlugValidation();
                }

                $oldValues = $this->auditState($lockedCategory);

                $lockedCategory->name_ar = $attributes['name_ar'];
                $lockedCategory->name_en = $attributes['name_en'];
                $lockedCategory->slug = $normalizedSlug;
                $lockedCategory->description_ar = $attributes['description_ar'] ?? null;
                $lockedCategory->description_en = $attributes['description_en'] ?? null;
                $lockedCategory->display_order = $attributes['display_order'];
                $lockedCategory->is_active = $attributes['is_active'];

                $changedFields = array_values(array_intersect(
                    self::ACCEPTED_FIELDS,
                    array_keys($lockedCategory->getDirty()),
                ));

                if ($changedFields === []) {
                    return $lockedCategory;
                }

                $lockedCategory->updated_by = $lockedActor->getKey();
                $lockedCategory->save();

                $this->auditLogger->log(
                    action: 'category.updated',
                    actor: $lockedActor,
                    subject: $lockedCategory,
                    oldValues: $oldValues,
                    newValues: [
                        ...$this->auditState($lockedCategory),
                        'changed_fields' => $changedFields,
                    ],
                    request: $request,
                );

                return $lockedCategory;
            });
        } catch (QueryException $exception) {
            if ($this->isDuplicateSlugViolation($exception)) {
                $this->throwDuplicateSlugValidation();
            }

            throw $exception;
        }
    }

    /** @return array{name_ar: string, name_en: string, slug: string, is_active: bool, display_order: int} */
    private function auditState(Category $category): array
    {
        return [
            'name_ar' => $category->name_ar,
            'name_en' => $category->name_en,
            'slug' => $category->slug,
            'is_active' => $category->is_active,
            'display_order' => $category->display_order,
        ];
    }

    private function isDuplicateSlugViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $message = Str::lower($exception->getMessage());

        return in_array($sqlState, ['23000', '23505'], true)
            && (in_array($driverCode, [19, 1062], true) || str_contains($message, 'unique'))
            && (str_contains($message, 'slug') || str_contains($message, 'categories_slug_unique'));
    }

    /** @return never */
    private function throwDuplicateSlugValidation(): void
    {
        throw ValidationException::withMessages([
            'slug' => 'This category slug is already in use.',
        ]);
    }
}
