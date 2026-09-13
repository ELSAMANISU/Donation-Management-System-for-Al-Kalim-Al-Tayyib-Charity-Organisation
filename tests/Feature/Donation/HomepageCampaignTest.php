<?php

namespace Tests\Feature\Donation;

use App\Models\Campaign;
use App\Models\Category;
use App\Models\HelpApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HomepageCampaignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['donations.driver' => 'sandbox']);
    }

    private function campaign(array $attributes = []): Campaign
    {
        return Campaign::factory()->active()->create($attributes + ['target_amount' => '100.00', 'raised_amount' => '25.00', 'expires_at' => now()->addDay()]);
    }

    public function test_limit_priority_locale_progress_image_and_safe_queries(): void
    {
        $application = HelpApplication::factory()->create(['private_story' => 'HOMEPAGE SECRET', 'decision_note' => 'HOMEPAGE NOTE']);
        $category = Category::factory()->create(['name_en' => 'English category', 'name_ar' => 'فئة عربية فريدة']);
        $first = $this->campaign(['help_application_id' => $application->id, 'category_id' => $category->id, 'title_en' => 'First English fixture', 'title_ar' => 'عنوان عربي فريد', 'summary_en' => 'English summary fixture', 'summary_ar' => 'ملخص عربي فريد', 'image_alt_en' => 'English alt fixture', 'image_alt_ar' => 'وصف عربي فريد', 'priority' => 100]);
        $second = $this->campaign(['title_en' => 'Second fixture', 'priority' => 99]);
        $third = $this->campaign(['title_en' => 'Third fixture', 'priority' => 98]);
        $fourth = $this->campaign(['title_en' => 'Fourth fixture', 'priority' => 97]);
        $excluded = $this->campaign(['title_en' => 'Fifth excluded fixture', 'priority' => 96]);
        DB::enableQueryLog();
        $response = $this->get('/en')->assertOk()->assertSeeInOrder([$first->title_en, $second->title_en, $third->title_en, $fourth->title_en])->assertDontSee($excluded->title_en)
            ->assertSee('25.00 SDG')->assertSee('100.00 SDG')->assertSee('75.00 SDG')->assertSee('aria-valuenow="25"', false)
            ->assertSee(route('cases.image', ['locale' => 'en', 'campaign' => $first->slug]), false)->assertSee(route('donations.create', ['locale' => 'en', 'campaign' => $first->slug]), false)
            ->assertSee($first->summary_en)->assertSee($first->image_alt_en)->assertSee($category->name_en)->assertDontSee($first->title_ar)->assertDontSee($first->summary_ar)->assertDontSee($category->name_ar)
            ->assertDontSee('HOMEPAGE SECRET')->assertDontSee('HOMEPAGE NOTE')->assertDontSee($application->reference);
        $this->assertCount(4, $response->viewData('cases'));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            foreach (['help_applications', 'help_application_id', 'donations', 'payment_attempts', 'private_story', 'decision_note', 'applicant_id'] as $private) {
                $this->assertStringNotContainsString($private, $query['query']);
            }
            if (str_contains($query['query'], 'campaigns')) {
                $this->assertStringNotContainsString('select *', $query['query']);
                $this->assertStringNotContainsString('title_ar', $query['query']);
            }
        }
        $this->get('/ar')->assertOk()->assertSee($first->title_ar)->assertSee($first->summary_ar)->assertSee($first->image_alt_ar)->assertSee($category->name_ar)->assertDontSee($first->title_en)->assertDontSee($first->summary_en);
        $this->get('/')->assertOk()->assertSee($first->title_en);
    }

    #[DataProvider('hiddenCampaigns')]
    public function test_homepage_uses_canonical_visibility(array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            if ($value === 'PAST') {
                $attributes[$key] = now()->subSecond();
            } if ($value === 'FUTURE') {
                $attributes[$key] = now()->addDay();
            }
        }
        $this->campaign($attributes + ['title_en' => 'HIDDEN HOMEPAGE FIXTURE']);
        $this->get('/en')->assertOk()->assertDontSee('HIDDEN HOMEPAGE FIXTURE')->assertSee('No published Campaigns at the moment.');
    }

    public static function hiddenCampaigns(): array
    {
        return [[['status' => 'draft']], [['status' => 'paused']], [['status' => 'cancelled']], [['published_at' => null]], [['published_at' => 'FUTURE']], [['expires_at' => 'PAST']], [['deleted_at' => 'PAST']]];
    }

    #[DataProvider('cardLocales')]
    public function test_progress_items_and_funded_status_precede_details_without_donation(string $locale, string $direction, string $progressLabel, string $fundedMessage, string $detailsLabel): void
    {
        $funded = Campaign::factory()->funded()->create(['title_en' => 'Funded presentation fixture', 'title_ar' => 'حملة مكتملة للعرض']);
        $active = $this->campaign(['title_en' => 'Active presentation fixture', 'title_ar' => 'حملة نشطة للعرض']);
        $response = $this->get('/'.$locale)->assertOk()->assertSee('lang="'.$locale.'" dir="'.$direction.'"', false);
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new \DOMXPath($document);
        $cards = $xpath->query('//section[@id="donationCases"]//article');
        $this->assertCount(2, $cards);
        foreach ($cards as $card) {
            $title = trim($xpath->query('.//h3', $card)->item(0)->textContent);
            $isFunded = $title === $funded->{'title_'.$locale};
            $row = $xpath->query('.//div[contains(concat(" ", normalize-space(@class), " "), " progress-label ")]', $card)->item(0);
            $items = $xpath->query('./span', $row);
            $this->assertCount(2, $items);
            $this->assertSame($progressLabel, trim($items->item(0)->textContent));
            $this->assertSame($isFunded ? '100%' : '25%', trim($items->item(1)->textContent));
            $actions = $xpath->query('.//div[@class="case-actions"]', $card)->item(0);
            $details = $xpath->query('./a[@class="btn-case"]', $actions);
            $this->assertCount(1, $details);
            $this->assertSame($detailsLabel, trim($details->item(0)->textContent));
            $this->assertSame(route('cases.show', ['locale' => $locale, 'campaign' => $isFunded ? $funded->slug : $active->slug]), $details->item(0)->getAttribute('href'));
            $status = $xpath->query('.//p[@role="status"]', $card);
            if ($isFunded) {
                $this->assertCount(1, $status);
                $this->assertSame($fundedMessage, trim($status->item(0)->textContent));
                $this->assertSame($status->item(0), $xpath->query('preceding-sibling::*[1]', $actions)->item(0));
                $this->assertCount(1, $xpath->query('./a', $actions));
                $this->assertCount(0, $xpath->query('.//a[contains(@href, "/donate")]', $card));
            } else {
                $this->assertCount(0, $status);
                $this->assertCount(2, $xpath->query('./a', $actions));
            }
        }
    }

    public static function cardLocales(): array
    {
        return [['en', 'ltr', 'Campaign progress', 'Campaign fully funded.', 'Campaign details'], ['ar', 'rtl', 'تقدم الحملة', 'اكتمل تمويل الحملة.', 'تفاصيل الحملة']];
    }

    public function test_category_visibility_empty_funded_and_legacy_controls(): void
    {
        $campaign = $this->campaign(['title_en' => 'Inactive homepage fixture']);
        $campaign->category->forceFill(['is_active' => false])->save();
        $this->get('/en')->assertOk()->assertDontSee($campaign->title_en)->assertSee('No published Campaigns at the moment.');
        $this->get('/ar')->assertOk()->assertSee('لا توجد حملات منشورة حالياً.');
        $funded = Campaign::factory()->funded()->create(['title_en' => 'Funded homepage fixture']);
        $page = $this->get('/en')->assertOk()->assertSee($funded->title_en)->assertSee('Campaign fully funded.')->assertDontSee('/'.$funded->slug.'/donate');
        $source = file_get_contents(resource_path('views/welcome.blade.php'));
        foreach (['SAR', '18,450', '34,200', '51,000', '12,300', '26,000', '40,000', '100,000', '30,000', 'case-input-group', 'parseFloat', 'handleDonate', 'Case 1: Quran Education', 'Min. 10', 'data-progress='] as $legacy) {
            $this->assertStringNotContainsString($legacy, $source);
        }
        $section = explode('<section id="donationCases">', $page->getContent())[1];
        $section = explode('</section>', $section)[0];
        $this->assertStringNotContainsString('unsplash', $section);
        $this->assertStringNotContainsString('<input', $section);
        $this->assertStringNotContainsString('<button', $section);
    }
}
