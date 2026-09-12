<?php

namespace Tests\Feature\Campaign;

use App\Models\Campaign;
use App\Models\Category;
use App\Models\HelpApplication;
use App\Services\CampaignProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicCampaignDisplayTest extends TestCase
{
    use RefreshDatabase;

    private function campaign(array $attributes = []): Campaign
    {
        $campaign = Campaign::factory()->active()->create(array_merge(['expires_at' => now()->addDay()], $attributes));
        $path = 'campaigns/'.$campaign->id.'/'.Str::uuid().'.png';
        Storage::disk('campaign_images')->put($path, UploadedFile::fake()->image('public.png')->getContent());
        $campaign->forceFill(['image_path' => $path, 'image_alt_en' => 'Public image', 'image_alt_ar' => 'صورة عامة'])->save();

        return $campaign->fresh();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSecond();
        Storage::fake('campaign_images');
    }

    public function test_exact_public_routes_and_locale_constraints(): void
    {
        foreach (['index' => '{locale}/cases', 'show' => '{locale}/cases/{campaign}', 'image' => '{locale}/cases/{campaign}/image'] as $name => $uri) {
            $route = Route::getRoutes()->getByName('cases.'.$name);
            $this->assertSame($uri, $route->uri());
            $this->assertSame(['GET', 'HEAD'], $route->methods());
            $this->assertSame(['web'], $route->gatherMiddleware());
        }
        $campaign = $this->campaign();
        foreach (['fr', 'EN', 'invalid'] as $locale) {
            $this->get('/'.$locale.'/cases')->assertNotFound();
            $this->get('/'.$locale.'/cases/'.$campaign->slug)->assertNotFound();
            $this->get('/'.$locale.'/cases/'.$campaign->slug.'/image')->assertNotFound();
        }
        $this->get('/en/cases/'.$campaign->id)->assertNotFound();
        $this->get('/en/cases/'.$campaign->slug)->assertOk();
    }

    public function test_future_publication_is_concealed_until_its_timestamp_without_mutating_campaign(): void
    {
        $campaign = $this->campaign(['published_at' => now()->addSecond(), 'title_en' => 'Future publication fixture']);
        $before = $campaign->getRawOriginal();
        foreach (['en', 'ar'] as $locale) {
            $this->get('/'.$locale.'/cases')->assertOk()->assertDontSee($campaign->{'title_'.$locale});
            $this->get('/'.$locale.'/cases/'.$campaign->slug)->assertNotFound();
            $this->get('/'.$locale.'/cases/'.$campaign->slug.'/image')->assertNotFound();
        }
        $this->assertSame($before, $campaign->fresh()->getRawOriginal());
        $this->travel(1)->seconds();
        $this->get('/en/cases')->assertOk()->assertSee($campaign->title_en);
        $this->get('/en/cases/'.$campaign->slug)->assertOk();
        $this->get('/en/cases/'.$campaign->slug.'/image')->assertOk();
        $this->assertSame($before, $campaign->fresh()->getRawOriginal());
    }

    #[DataProvider('currentAndPastPublicationTimes')]
    public function test_current_and_past_publication_remain_visible_on_every_public_surface(int $secondsAgo): void
    {
        $campaign = $this->campaign(['published_at' => now()->subSeconds($secondsAgo)]);
        $before = $campaign->getRawOriginal();
        foreach (['en', 'ar'] as $locale) {
            $this->get('/'.$locale.'/cases')->assertOk()->assertSee($campaign->{'title_'.$locale});
            $this->get('/'.$locale.'/cases/'.$campaign->slug)->assertOk();
            $this->get('/'.$locale.'/cases/'.$campaign->slug.'/image')->assertOk();
        }
        $this->assertSame($before, $campaign->fresh()->getRawOriginal());
    }

    public static function currentAndPastPublicationTimes(): array
    {
        return ['now' => [0], 'past' => [1]];
    }

    #[DataProvider('hiddenStates')]
    public function test_nonpublic_campaigns_are_concealed_on_every_public_surface(array $attributes): void
    {
        $attributes = array_map(fn ($value) => $value === 'PAST' ? now()->subDay() : ($value === 'NOW' ? now() : $value), $attributes);
        $campaign = $this->campaign($attributes + ['title_en' => 'Hidden fixture']);
        $this->get('/en/cases')->assertOk()->assertDontSee('Hidden fixture');
        $this->get('/en/cases/'.$campaign->slug)->assertNotFound();
        $this->get('/en/cases/'.$campaign->slug.'/image')->assertNotFound();
        $this->assertSame($campaign->getRawOriginal(), $campaign->fresh()->getRawOriginal());
    }

    public static function hiddenStates(): array
    {
        return [[['status' => 'draft']], [['status' => 'paused']], [['status' => 'cancelled']], [['published_at' => null]], [['expires_at' => 'PAST']], [['expires_at' => 'NOW']], [['deleted_at' => 'PAST']]];
    }

    #[DataProvider('visibleStatuses')]
    public function test_established_published_status_allowlist_is_preserved(string $status): void
    {
        $campaign = $this->campaign(['status' => $status]);
        $this->get('/en/cases')->assertSee($campaign->title_en);
        $this->get('/en/cases/'.$campaign->slug)->assertOk();
        $this->get('/en/cases/'.$campaign->slug.'/image')->assertOk();
    }

    public static function visibleStatuses(): array
    {
        return [['active'], ['funded'], ['aid_delivery'], ['completed']];
    }

    public function test_category_filters_never_expand_scope_and_cards_use_database_categories(): void
    {
        $health = Category::factory()->create(['slug' => 'health']);
        $food = Category::factory()->create(['slug' => 'food']);
        $a = $this->campaign(['category_id' => $health->id, 'title_en' => 'Health fixture']);
        $b = $this->campaign(['category_id' => $food->id, 'title_en' => 'Food fixture']);
        $this->get('/en/cases?category=health')->assertOk()->assertSee($a->title_en)->assertDontSee($b->title_en)->assertSee($health->name_en);
        $this->get('/en/cases?category=food')->assertOk()->assertSee($b->title_en)->assertDontSee($a->title_en);
        foreach (['absent', (string) $health->id, '', 'HEALTH', 'health/food', 'health%20', '%3Cscript%3E'] as $filter) {
            $this->get('/en/cases?category='.$filter)->assertNotFound();
        }
        $this->get('/en/cases?category[]=health')->assertNotFound();
        $health->forceFill(['is_active' => false])->save();
        $this->get('/en/cases?category=health')->assertNotFound();
        $this->get('/en/cases/'.$a->slug)->assertNotFound();
        $this->get('/en/cases/'.$a->slug.'/image')->assertNotFound();
        $food->delete();
        $this->get('/en/cases?category=food')->assertNotFound();
        $this->get('/en/cases')->assertDontSee('Food fixture')->assertDontSee('Health fixture');
    }

    public function test_priority_order_pagination_and_only_canonical_filter_is_preserved(): void
    {
        $category = Category::factory()->create(['slug' => 'health']);
        Campaign::factory()->active()->count(16)->create(['category_id' => $category->id, 'priority' => 1, 'published_at' => now()->subDay()]);
        $first = $this->campaign(['category_id' => $category->id, 'priority' => 100, 'title_en' => 'First priority']);
        $second = $this->campaign(['category_id' => $category->id, 'priority' => 99, 'title_en' => 'Second priority']);
        $response = $this->get('/en/cases?category=health&private_story=SECRET&sort=wrong');
        $response->assertOk()->assertSeeInOrder(['First priority', 'Second priority'])->assertDontSee('SECRET');
        $paginator = $response->viewData('cases');
        $this->assertCount(15, $paginator);
        $this->assertSame(18, $paginator->total());
        $this->assertSame([$first->id, $second->id], $paginator->getCollection()->take(2)->pluck('id')->all());
        $this->assertStringContainsString('category=health', $paginator->nextPageUrl());
        $this->assertStringNotContainsString('private_story', $paginator->nextPageUrl());
        $this->assertStringNotContainsString('sort=', $paginator->nextPageUrl());
        $this->get('/en/cases?category=health&page=2')->assertOk()->assertViewHas('cases', fn ($cases) => $cases->count() === 3);
    }

    public function test_localized_copy_is_escaped_without_opposite_language_or_private_queries(): void
    {
        $application = HelpApplication::factory()->create(['private_story' => 'ULTRA PRIVATE STORY', 'decision_note' => 'ULTRA PRIVATE NOTE']);
        $category = Category::factory()->create(['name_en' => '<b>Category</b>']);
        $campaign = $this->campaign(['help_application_id' => $application->id, 'category_id' => $category->id,
            'title_en' => '<script>Title</script>', 'summary_en' => '<b>Summary</b>', 'story_en' => '<script>Story</script>',
            'title_ar' => 'عنوان عربي منفصل', 'summary_ar' => 'ملخص عربي منفصل', 'story_ar' => 'قصة عربية منفصلة']);
        $campaign->forceFill(['image_alt_en' => '" onerror="alert(1)'])->save();
        DB::enableQueryLog();
        foreach (['/en/cases', '/en/cases/'.$campaign->slug] as $url) {
            $response = $this->get($url)->assertOk()->assertSee($campaign->title_en)->assertSee($campaign->summary_en)->assertSee($category->name_en)
                ->assertDontSee('<script>Title</script>', false)->assertDontSee('<b>Summary</b>', false)->assertDontSee('<b>Category</b>', false)
                ->assertDontSee($campaign->title_ar)->assertDontSee($campaign->summary_ar)->assertDontSee($campaign->image_path)
                ->assertDontSee('ULTRA PRIVATE')->assertDontSee($application->reference)->assertDontSee('name="amount"', false)->assertDontSee('id="donateForm"', false)
                ->assertSee('Donations will be available soon');
            $this->assertStringNotContainsString('" onerror="alert(1)', $response->getContent());
        }
        $this->get('/en/cases/'.$campaign->slug)->assertSee($campaign->story_en)->assertDontSee('<script>Story</script>', false)->assertDontSee($campaign->story_ar);
        $this->get('/ar/cases/'.$campaign->slug)->assertSee($campaign->story_ar)->assertSee('lang="ar" dir="rtl"', false)->assertDontSee($campaign->story_en);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            $this->assertStringNotContainsString('help_applications', $query['query']);
            $this->assertStringNotContainsString('help_application_id', $query['query']);
            if (str_contains($query['query'], 'campaigns') && str_starts_with($query['query'], 'select')) {
                $this->assertStringNotContainsString('select *', $query['query']);
            }
        }
    }

    public function test_public_image_has_detected_mime_safe_headers_and_no_path(): void
    {
        $campaign = $this->campaign();
        $this->get('/en/cases/'.$campaign->slug.'/image')->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'no-store, private')->assertDontSee($campaign->image_path);
        Storage::disk('campaign_images')->delete($campaign->image_path);
        $this->get('/en/cases/'.$campaign->slug.'/image')->assertNotFound();
        Storage::disk('campaign_images')->put($campaign->image_path, 'corrupt');
        $this->get('/en/cases/'.$campaign->slug.'/image')->assertNotFound();
        $campaign->forceFill(['image_path' => '../private-document.png'])->save();
        $this->get('/en/cases/'.$campaign->slug.'/image')->assertNotFound();
    }

    #[DataProvider('progressValues')]
    public function test_progress_is_safe_exact_and_capped(string $raised, string $target, int $expected): void
    {
        $this->assertSame($expected, CampaignProgress::percentage($raised, $target));
        $campaign = $this->campaign(['raised_amount' => $raised, 'target_amount' => $target]);
        $this->get('/en/cases/'.$campaign->slug)->assertOk()->assertSee('aria-valuenow="'.$expected.'"', false);
        $this->assertSame($campaign->raised_amount, $campaign->fresh()->raised_amount);
    }

    public static function progressValues(): array
    {
        return [['0.00', '100.00', 0], ['50.00', '100.00', 50], ['100.00', '100.00', 100], ['200.00', '100.00', 100], ['0.00', '0.00', 0], ['1.00', '0.00', 0], ['9999999999999999.98', '9999999999999999.99', 100]];
    }

    public function test_demo_data_and_payment_controls_are_removed_from_production_cases(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/DonationCaseController.php'));
        foreach (['allCases', 'unsplash', 'Heart Surgery for a Sick Child', 'Ahmed, a five-year-old'] as $demo) {
            $this->assertStringNotContainsString($demo, $controller);
        }
        $this->assertDoesNotMatchRegularExpression('/donate|payment|stripe|paypal/i', $controller);
        foreach (['index', 'show', 'progress'] as $view) {
            $source = file_get_contents(resource_path('views/cases/'.$view.'.blade.php'));
            $this->assertStringNotContainsString('unsplash', $source);
            $this->assertDoesNotMatchRegularExpression('/donate|payment|stripe|paypal/i', $source);
        }
        $campaign = $this->campaign();
        $this->get('/en/cases/'.$campaign->slug)->assertDontSee('Donate Now')->assertDontSee('handleDonate')->assertDontSee('name="amount"', false);
    }
}
