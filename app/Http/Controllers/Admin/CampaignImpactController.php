<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Services\CampaignImpactInput;
use App\Services\CampaignImpactService;
use App\Services\ImpactFormTokens;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class CampaignImpactController extends Controller
{
    public function edit(Request $request, Campaign $campaign, ImpactFormTokens $tokens)
    {
        abort_unless($request->query->count() === 0 && Gate::allows('publishImpact', $campaign)
            && $campaign->getRawOriginal('status') === 'completed' && $campaign->completed_at !== null, 404);
        $version = CampaignImpactService::version($campaign);
        $draftToken = $campaign->impact_published_at === null ? $tokens->issue($request, $campaign->slug, 'draft', $version) : null;
        $publishToken = $campaign->impact_published_at === null && CampaignImpactInput::text($campaign->impact_update_ar) !== null
            && CampaignImpactInput::text($campaign->impact_update_en) !== null
            ? $tokens->issue($request, $campaign->slug, 'publish', $version) : null;

        return response()->view('admin.campaigns.impact', compact('campaign', 'draftToken', 'publishToken'), 200,
            ['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache']);
    }

    public function draft(Request $request, Campaign $campaign, ImpactFormTokens $tokens, CampaignImpactService $service)
    {
        $version = $tokens->consume($request, 'draft');
        $keys = array_keys($request->request->all());
        sort($keys);
        abort_unless($request->query->count() === 0 && $request->files->count() === 0
            && in_array($keys, [['_token', 'impact_token', 'impact_update_ar', 'impact_update_en'], ['impact_token', 'impact_update_ar', 'impact_update_en']], true), 404);
        $service->change($request->user(), $campaign, 'draft', $version, $request->only('impact_update_ar', 'impact_update_en'));

        return redirect()->route('admin.campaigns.impact.edit', $campaign);
    }

    public function publish(Request $request, Campaign $campaign, ImpactFormTokens $tokens, CampaignImpactService $service)
    {
        $version = $tokens->consume($request, 'publish');
        $keys = array_keys($request->request->all());
        sort($keys);
        abort_unless($request->query->count() === 0 && $request->files->count() === 0
            && in_array($keys, [['_token', 'impact_token'], ['impact_token']], true), 404);
        $service->change($request->user(), $campaign, 'publish', $version);

        return redirect()->route('admin.campaigns.impact.edit', $campaign);
    }
}
