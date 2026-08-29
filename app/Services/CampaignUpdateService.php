<?php

namespace App\Services;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\Category;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CampaignUpdateService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array{category_id:int,title_ar:string,title_en:string,summary_ar:string,summary_en:string,story_ar:string,story_en:string,target_amount:string} $attributes */
    public function update(User $actor, Campaign $campaign, array $attributes, Request $request): Campaign
    {
        return DB::transaction(function () use ($actor, $campaign, $attributes, $request): Campaign {
            $lockedActor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $lockedCampaign = Campaign::query()->lockForUpdate()->findOrFail($campaign->id);
            Gate::forUser($lockedActor)->authorize('update', $lockedCampaign);
            if ($lockedCampaign->status !== CampaignStatus::Draft) {
                abort(403);
            }
            if ($lockedCampaign->raised_amount !== '0.00') {
                throw ValidationException::withMessages(['target_amount' => 'This draft has an unexpected raised balance and cannot be edited. / تحتوي هذه المسودة على رصيد مرفوع غير متوقع ولا يمكن تعديلها.']);
            }
            $category = Category::query()->lockForUpdate()->find($attributes['category_id']);
            if (! $category || ! $category->is_active) {
                throw ValidationException::withMessages(['category_id' => 'The selected category is unavailable. / الفئة المحددة غير متاحة.']);
            }

            $editable = ['category_id', 'title_ar', 'title_en', 'summary_ar', 'summary_en', 'story_ar', 'story_en', 'target_amount'];
            $changed = [];
            foreach ($editable as $field) {
                $current = $field === 'category_id' ? $lockedCampaign->{$field} : (string) $lockedCampaign->{$field};
                $desired = $field === 'category_id' ? $category->id : $attributes[$field];
                if ($current !== $desired) {
                    $changed[] = $field;
                }
            }
            if ($changed === []) {
                return $lockedCampaign;
            }

            $old = $this->auditState($lockedCampaign, $changed);
            $lockedCampaign->category_id = $category->id;
            foreach (['title_ar', 'title_en', 'summary_ar', 'summary_en', 'story_ar', 'story_en', 'target_amount'] as $field) {
                $lockedCampaign->{$field} = $attributes[$field];
            }
            $lockedCampaign->updated_by = $lockedActor->id;
            $lockedCampaign->save();
            $this->auditLogger->log('campaign.updated', $lockedActor, $lockedCampaign, $old, $this->auditState($lockedCampaign, $changed), $request);

            return $lockedCampaign;
        });
    }

    /** @param list<string> $changed */
    private function auditState(Campaign $campaign, array $changed): array
    {
        return ['category_id' => $campaign->category_id, 'target_amount' => $campaign->target_amount, 'status' => $campaign->status->value, 'raised_amount' => $campaign->raised_amount, 'changed_fields' => $changed];
    }
}
