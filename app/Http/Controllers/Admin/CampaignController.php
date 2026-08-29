<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCampaignImageRequest;
use App\Http\Requests\Admin\StoreCampaignRequest;
use App\Http\Requests\Admin\UpdateCampaignRequest;
use App\Models\Campaign;
use App\Models\Category;
use App\Services\CampaignCreationService;
use App\Services\CampaignImageService;
use App\Services\CampaignUpdateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CampaignController extends Controller
{
    public function __construct(
        private readonly CampaignCreationService $creationService,
        private readonly CampaignUpdateService $updateService,
        private readonly CampaignImageService $imageService,
    ) {}

    public function index(): View
    {
        Gate::authorize('viewAny', Campaign::class);
        $campaigns = Campaign::query()
            ->select(['id', 'category_id', 'slug', 'title_ar', 'title_en', 'status', 'target_amount', 'created_at'])
            ->with(['category' => fn ($query) => $query->withTrashed()->select(['id', 'name_ar', 'name_en'])])
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(15);

        return view('admin.campaigns.index', compact('campaigns'));
    }

    public function create(): View
    {
        Gate::authorize('create', Campaign::class);
        $categories = Category::query()->active()->select(['id', 'name_ar', 'name_en'])->inDisplayOrder()->get();

        return view('admin.campaigns.create', compact('categories'));
    }

    public function store(StoreCampaignRequest $request): RedirectResponse
    {
        Gate::authorize('create', Campaign::class);
        $this->creationService->create($request->user(), $request->safe()->only([
            'category_id', 'slug', 'title_ar', 'title_en', 'summary_ar', 'summary_en', 'story_ar', 'story_en', 'target_amount',
        ]), $request);

        return redirect()->route('admin.campaigns.index')->with('status', 'campaign-created');
    }

    public function edit(Campaign $campaign): View
    {
        Gate::authorize('update', $campaign);
        $categories = Category::query()->active()->select(['id', 'name_ar', 'name_en'])->inDisplayOrder()->get();
        $currentCategory = $campaign->category()->withTrashed()->first();

        return view('admin.campaigns.edit', compact('campaign', 'categories', 'currentCategory'));
    }

    public function update(UpdateCampaignRequest $request, Campaign $campaign): RedirectResponse
    {
        Gate::authorize('update', $campaign);
        $updated = $this->updateService->update($request->user(), $campaign, $request->safe()->only([
            'category_id', 'title_ar', 'title_en', 'summary_ar', 'summary_en', 'story_ar', 'story_en', 'target_amount',
        ]), $request);

        return redirect()->route('admin.campaigns.index')->with('status', $updated->wasChanged() ? 'campaign-updated' : 'campaign-unchanged');
    }

    public function showImage(Campaign $campaign): Response
    {
        Gate::authorize('update', $campaign);
        $image = $this->imageService->preview($campaign);

        return response($image['content'], 200, [
            'Content-Type' => $image['mime'],
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function storeImage(StoreCampaignImageRequest $request, Campaign $campaign): RedirectResponse
    {
        Gate::authorize('update', $campaign);
        $this->imageService->upload(
            $request->user(),
            $campaign,
            $request->file('image'),
            $request->validated('image_alt_ar'),
            $request->validated('image_alt_en'),
            $request,
        );

        return redirect()->route('admin.campaigns.edit', $campaign)->with('status', 'campaign-image-updated');
    }

    public function destroyImage(Request $request, Campaign $campaign): RedirectResponse
    {
        Gate::authorize('update', $campaign);
        $updated = $this->imageService->remove($request->user(), $campaign, $request);

        return redirect()->route('admin.campaigns.edit', $campaign)
            ->with('status', $updated->wasChanged() ? 'campaign-image-removed' : 'campaign-image-unchanged');
    }
}
