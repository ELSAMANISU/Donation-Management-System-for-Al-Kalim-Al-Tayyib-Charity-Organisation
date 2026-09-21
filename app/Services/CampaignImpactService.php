<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\HelpApplication;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class CampaignImpactService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public static function version(Campaign $campaign): string
    {
        return hash('sha256', implode('|', [$campaign->id, $campaign->getRawOriginal('status'),
            $campaign->getRawOriginal('completed_at'), $campaign->getRawOriginal('impact_published_at'),
            $campaign->getRawOriginal('impact_update_ar'), $campaign->getRawOriginal('impact_update_en')]));
    }

    public function change(User $actor, Campaign $campaign, string $action, string $version, array $input = []): void
    {
        DB::transaction(function () use ($actor, $campaign, $action, $version, $input): void {
            $application = $campaign->help_application_id === null ? null : HelpApplication::query()
                ->select(['id', 'status', 'open_slot'])->whereKey($campaign->help_application_id)->lockForUpdate()->first();
            $freshActor = User::query()->select(['id', 'role', 'is_active', 'must_change_password'])->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $locked = Campaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            abort_unless(Gate::forUser($freshActor)->allows('publishImpact', $locked)
                && $locked->getRawOriginal('status') === 'completed' && $locked->completed_at !== null
                && $locked->impact_published_at === null && $application
                && $application->getRawOriginal('status') === 'completed' && $application->open_slot === null
                && hash_equals(self::version($locked), $version), 404);
            $timestamp = CarbonImmutable::now(config('app.timezone'))->startOfSecond();
            if ($action === 'draft') {
                abort_unless(count($input) === 2 && isset($input['impact_update_ar'], $input['impact_update_en']), 404);
                $ar = CampaignImpactInput::text($input['impact_update_ar']);
                $en = CampaignImpactInput::text($input['impact_update_en']);
                abort_unless($ar !== null && $en !== null, 404);
                $locked->impact_update_ar = $ar;
                $locked->impact_update_en = $en;
                $auditAction = 'campaign.impact_draft_saved';
            } else {
                abort_unless($action === 'publish' && $input === []
                    && CampaignImpactInput::text($locked->impact_update_ar) === $locked->impact_update_ar
                    && CampaignImpactInput::text($locked->impact_update_en) === $locked->impact_update_en, 404);
                $locked->impact_published_at = $timestamp;
                $auditAction = 'campaign.impact_published';
            }
            $locked->updated_by = $freshActor->id;
            $locked->updated_at = $timestamp;
            $locked->timestamps = false;
            $locked->save();
            $this->audit->log($auditAction, actor: $freshActor, subject: $locked,
                oldValues: ['impact_published' => false], newValues: ['impact_published' => $action === 'publish'], createdAt: $timestamp);
        });
    }
}
