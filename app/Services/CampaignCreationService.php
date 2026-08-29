<?php

namespace App\Services;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\Category;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CampaignCreationService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array{category_id: int, slug: string, title_ar: string, title_en: string, summary_ar: string, summary_en: string, story_ar: string, story_en: string, target_amount: string} $attributes */
    public function create(User $actor, array $attributes, Request $request): Campaign
    {
        $slug = Campaign::normalizeSlug($attributes['slug']);

        try {
            return DB::transaction(function () use ($actor, $attributes, $slug, $request): Campaign {
                $lockedActor = User::query()->lockForUpdate()->findOrFail($actor->getKey());
                Gate::forUser($lockedActor)->authorize('create', Campaign::class);

                $category = Category::query()->lockForUpdate()->find($attributes['category_id']);
                if (! $category || ! $category->is_active) {
                    throw ValidationException::withMessages(['category_id' => 'The selected category is unavailable. / الفئة المحددة غير متاحة.']);
                }

                if (Campaign::withTrashed()->where('slug', $slug)->exists()) {
                    $this->throwDuplicateSlugValidation();
                }

                $campaign = new Campaign;
                $campaign->category_id = $category->id;
                $campaign->slug = $slug;
                foreach (['title_ar', 'title_en', 'summary_ar', 'summary_en', 'story_ar', 'story_en'] as $field) {
                    $campaign->{$field} = $attributes[$field];
                }
                $campaign->target_amount = $attributes['target_amount'];
                $campaign->raised_amount = '0.00';
                $campaign->status = CampaignStatus::Draft;
                $campaign->is_featured = false;
                $campaign->is_urgent = false;
                $campaign->priority = 0;
                foreach (['image_path', 'image_alt_ar', 'image_alt_en', 'expires_at', 'published_at', 'paused_at', 'pause_reason', 'funded_at', 'aid_delivery_started_at', 'completed_at', 'cancelled_at', 'cancellation_reason', 'impact_update_ar', 'impact_update_en', 'deleted_at'] as $field) {
                    $campaign->{$field} = null;
                }
                $campaign->created_by = $lockedActor->id;
                $campaign->updated_by = $lockedActor->id;
                $campaign->save();

                $this->auditLogger->log('campaign.created', $lockedActor, $campaign, null, [
                    'category_id' => $campaign->category_id,
                    'slug' => $campaign->slug,
                    'status' => $campaign->status->value,
                    'target_amount' => $campaign->target_amount,
                    'raised_amount' => $campaign->raised_amount,
                    'is_featured' => $campaign->is_featured,
                    'is_urgent' => $campaign->is_urgent,
                    'priority' => $campaign->priority,
                ], $request);

                return $campaign;
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
        $state = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $code = (int) ($exception->errorInfo[1] ?? 0);
        $diagnostic = (string) ($exception->errorInfo[2] ?? '');

        if ($state === '23000' && $code === 1062) {
            return preg_match('/\bfor key\s+[\'"`](?:[^\'"`]+\.)?campaigns_slug_unique[\'"`]/i', $diagnostic) === 1;
        }

        return in_array($state, ['23000', '23505'], true)
            && $code === 19
            && preg_match('/\bunique constraint failed:\s*[`"\[]?campaigns[`"\]]?\.[`"\[]?slug[`"\]]?(?:\s|$)/i', $diagnostic) === 1;
    }

    private function throwDuplicateSlugValidation(): never
    {
        throw ValidationException::withMessages(['slug' => 'This campaign slug is already in use. / مُعرّف الحملة مستخدم بالفعل.']);
    }
}
