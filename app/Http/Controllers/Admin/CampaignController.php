<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCampaignRequest;
use App\Models\Campaign;
use App\Models\Category;
use App\Services\CampaignCreationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CampaignController extends Controller
{
    public function __construct(private readonly CampaignCreationService $creationService) {}

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
}
