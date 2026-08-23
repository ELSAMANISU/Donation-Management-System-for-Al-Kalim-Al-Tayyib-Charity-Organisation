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

class CategoryCreationService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array{name_ar: string, name_en: string, slug: string, description_ar?: string|null, description_en?: string|null}  $attributes
     */
    public function create(User $actor, array $attributes, Request $request): Category
    {
        $normalizedSlug = Category::normalizeSlug($attributes['slug']);

        try {
            return DB::transaction(function () use ($actor, $attributes, $normalizedSlug, $request): Category {
                $lockedActor = User::query()->lockForUpdate()->findOrFail($actor->getKey());

                Gate::forUser($lockedActor)->authorize('create', Category::class);

                if (! $lockedActor->is_active) {
                    abort(403);
                }

                if (Category::withTrashed()->where('slug', $normalizedSlug)->exists()) {
                    $this->throwDuplicateSlugValidation();
                }

                $category = new Category;
                $category->name_ar = $attributes['name_ar'];
                $category->name_en = $attributes['name_en'];
                $category->slug = $normalizedSlug;
                $category->description_ar = $attributes['description_ar'] ?? null;
                $category->description_en = $attributes['description_en'] ?? null;
                $category->is_active = true;
                $category->display_order = 0;
                $category->created_by = $lockedActor->getKey();
                $category->updated_by = $lockedActor->getKey();
                $category->icon = null;
                $category->image_path = null;
                $category->save();

                $this->auditLogger->log(
                    action: 'category.created',
                    actor: $lockedActor,
                    subject: $category,
                    newValues: [
                        'name_ar' => $category->name_ar,
                        'name_en' => $category->name_en,
                        'slug' => $category->slug,
                        'is_active' => $category->is_active,
                        'display_order' => $category->display_order,
                    ],
                    request: $request,
                );

                return $category;
            });
        } catch (QueryException $exception) {
            if ($this->isDuplicateSlugViolation($exception)) {
                $this->throwDuplicateSlugValidation();
            }

            throw $exception;
        }
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
