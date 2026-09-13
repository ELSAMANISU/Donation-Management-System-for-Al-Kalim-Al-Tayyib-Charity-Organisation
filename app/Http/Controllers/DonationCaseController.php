<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\CampaignImageService;
use App\Services\PublicCampaignQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class DonationCaseController extends Controller
{
    private function visible(): Builder
    {
        return app(PublicCampaignQuery::class)->visible();
    }

    private function content(string $locale, bool $detail = false): Builder
    {
        return app(PublicCampaignQuery::class)->content($locale, $detail);
    }

    public function index(Request $request, string $locale)
    {
        $query = $this->content($locale);
        $category = null;
        if ($request->query->has('category')) {
            $slug = $request->query('category');
            abort_unless(is_string($slug) && strlen($slug) <= 160 && preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) === 1, 404);
            $category = Category::query()->active()->select(['id', 'slug', 'name_'.$locale])->where('slug', $slug)->firstOrFail();
            $query->where('category_id', $category->id);
        }
        $cases = $query->inPriorityOrder()->paginate(15);
        if ($category) {
            $cases->appends(['category' => $category->slug]);
        }
        $categories = Category::query()->active()->select(['id', 'slug', 'name_'.$locale])->inDisplayOrder()->get();

        return view('cases.index', compact('cases', 'category', 'categories', 'locale'));
    }

    public function show(string $locale, string $campaign)
    {
        $case = $this->content($locale, true)->where('slug', $campaign)->firstOrFail();

        return view('cases.show', compact('case', 'locale'));
    }

    public function image(string $locale, string $campaign, CampaignImageService $images)
    {
        $visible = $this->visible()->select(['id', 'image_path'])->where('slug', $campaign)->firstOrFail();
        $image = $images->preview($visible);

        return response($image['content'], 200, ['Content-Type' => $image['mime'], 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache']);
    }
}
