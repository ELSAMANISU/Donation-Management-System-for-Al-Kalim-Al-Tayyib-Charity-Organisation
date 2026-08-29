<?php

namespace Tests\Feature\Campaign;

use App\Enums\CampaignStatus;
use App\Http\Controllers\DonationCaseController;
use App\Models\Campaign;
use App\Models\Category;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CampaignDataFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_contains_only_the_expected_campaign_foundation_columns(): void
    {
        $expected = [
            'id', 'category_id', 'slug', 'title_ar', 'title_en', 'summary_ar', 'summary_en',
            'story_ar', 'story_en', 'target_amount', 'raised_amount', 'status', 'is_featured',
            'is_urgent', 'priority', 'image_path', 'image_alt_ar', 'image_alt_en', 'expires_at',
            'published_at', 'paused_at', 'pause_reason', 'funded_at', 'aid_delivery_started_at',
            'completed_at', 'cancelled_at', 'cancellation_reason', 'impact_update_ar',
            'impact_update_en', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at',
        ];

        $this->assertEqualsCanonicalizing($expected, Schema::getColumnListing('campaigns'));
        $this->assertFalse(Schema::hasColumn('campaigns', 'help_application_id'));

        foreach (['beneficiary_id', 'applicant_id', 'identity_number', 'passport_number',
            'receiving_method', 'account_identifier', 'document_path', 'private_notes',
            'transaction_id', 'donation_id', 'delivered_amount', 'remaining_amount',
            'progress', 'image_original_name', 'image_mime_type', 'image_size', 'display_order'] as $column) {
            $this->assertFalse(Schema::hasColumn('campaigns', $column), $column.' must not exist.');
        }
    }

    public function test_enum_has_exact_values_and_campaign_defaults_and_casts_are_correct(): void
    {
        $this->assertSame(
            ['draft', 'active', 'paused', 'funded', 'aid_delivery', 'completed', 'cancelled'],
            array_column(CampaignStatus::cases(), 'value')
        );

        $campaign = Campaign::factory()->create([
            'target_amount' => '1234567890.78',
            'expires_at' => now()->addDay(),
        ])->fresh();

        $this->assertSame(CampaignStatus::Draft, $campaign->status);
        $this->assertSame('1234567890.78', $campaign->target_amount);
        $this->assertSame('0.00', $campaign->raised_amount);
        $this->assertFalse($campaign->is_featured);
        $this->assertFalse($campaign->is_urgent);
        $this->assertSame(0, $campaign->priority);
        $this->assertInstanceOf(CarbonImmutable::class, $campaign->expires_at);
        $this->assertNull($campaign->published_at);
        $this->assertNull($campaign->deleted_at);
        $this->assertNotNull($campaign->category);
        $this->assertTrue($campaign->category->is_active);
    }

    public function test_required_bilingual_public_content_persists(): void
    {
        $campaign = Campaign::factory()->create([
            'title_ar' => 'عنوان عربي عام',
            'title_en' => 'Public English Title',
            'summary_ar' => 'ملخص عربي آمن',
            'summary_en' => 'Privacy-safe English summary',
            'story_ar' => 'قصة عربية عامة وآمنة',
            'story_en' => 'A privacy-safe English public story',
        ]);

        $this->assertSame('عنوان عربي عام', $campaign->title_ar);
        $this->assertSame('Public English Title', $campaign->title_en);
        $this->assertSame('ملخص عربي آمن', $campaign->summary_ar);
        $this->assertSame('Privacy-safe English summary', $campaign->summary_en);
        $this->assertSame('قصة عربية عامة وآمنة', $campaign->story_ar);
        $this->assertSame('A privacy-safe English public story', $campaign->story_en);
    }

    public function test_ordinary_mass_assignment_cannot_set_protected_state(): void
    {
        $actor = User::factory()->admin()->create();
        $originalCategory = Category::factory()->create();
        $secondCategory = Category::factory()->create();
        $campaign = Campaign::factory()->for($originalCategory)->create([
            'target_amount' => '900.00',
            'title_en' => 'Original public title',
        ]);
        $attemptedLifecycleTime = now()->addWeek();

        $campaign->fill([
            'category_id' => $secondCategory->id,
            'target_amount' => '999999.99',
            'raised_amount' => '800.00',
            'status' => CampaignStatus::Completed,
            'is_featured' => true,
            'is_urgent' => true,
            'priority' => 99,
            'expires_at' => $attemptedLifecycleTime,
            'published_at' => $attemptedLifecycleTime,
            'paused_at' => $attemptedLifecycleTime,
            'pause_reason' => 'Unsafe pause reason',
            'funded_at' => $attemptedLifecycleTime,
            'aid_delivery_started_at' => $attemptedLifecycleTime,
            'completed_at' => $attemptedLifecycleTime,
            'cancelled_at' => $attemptedLifecycleTime,
            'cancellation_reason' => 'Unsafe cancellation reason',
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'deleted_at' => $attemptedLifecycleTime,
            'title_en' => 'Updated public title',
        ])->save();
        $campaign->refresh();

        $this->assertSame($originalCategory->id, $campaign->category_id);
        $this->assertNotSame($secondCategory->id, $campaign->category_id);
        $this->assertSame('900.00', $campaign->target_amount);
        $this->assertNotSame('999999.99', $campaign->target_amount);
        $this->assertSame('0.00', $campaign->raised_amount);
        $this->assertSame(CampaignStatus::Draft, $campaign->status);
        $this->assertFalse($campaign->is_featured);
        $this->assertFalse($campaign->is_urgent);
        $this->assertSame(0, $campaign->priority);
        $this->assertNull($campaign->expires_at);
        $this->assertNull($campaign->published_at);
        $this->assertNull($campaign->paused_at);
        $this->assertNull($campaign->pause_reason);
        $this->assertNull($campaign->funded_at);
        $this->assertNull($campaign->aid_delivery_started_at);
        $this->assertNull($campaign->completed_at);
        $this->assertNull($campaign->cancelled_at);
        $this->assertNull($campaign->cancellation_reason);
        $this->assertNull($campaign->created_by);
        $this->assertNull($campaign->updated_by);
        $this->assertNull($campaign->deleted_at);
        $this->assertSame('Updated public title', $campaign->title_en);
    }

    public function test_slugs_are_normalized_and_globally_unique_across_soft_deletes(): void
    {
        $campaign = Campaign::factory()->create(['slug' => ' Emergency HELP ']);
        $this->assertSame('emergency-help', $campaign->slug);
        $campaign->delete();

        $this->expectException(QueryException::class);
        Campaign::factory()->create(['slug' => 'emergency-help']);
    }

    public function test_category_relationship_survives_soft_delete_and_restore(): void
    {
        $category = Category::factory()->create();
        $campaign = Campaign::factory()->for($category)->create();
        $this->assertTrue($campaign->category->is($category));
        $this->assertTrue($category->campaigns->contains($campaign));

        $category->delete();
        $this->assertDatabaseHas('campaigns', ['id' => $campaign->id, 'category_id' => $category->id]);
        $this->assertTrue($campaign->fresh()->category()->withTrashed()->firstOrFail()->trashed());

        $category->restore();
        $this->assertTrue($campaign->fresh()->category->is($category));
    }

    public function test_physical_category_deletion_is_blocked_for_active_campaign(): void
    {
        $category = Category::factory()->create();
        Campaign::factory()->for($category)->create();

        $this->expectException(QueryException::class);
        $category->forceDelete();
    }

    public function test_physical_category_deletion_is_blocked_for_soft_deleted_campaign(): void
    {
        $category = Category::factory()->create();
        Campaign::factory()->for($category)->trashed()->create();

        $this->expectException(QueryException::class);
        $category->forceDelete();
    }

    public function test_creator_and_updater_relationships_and_null_on_user_deletion(): void
    {
        $creator = User::factory()->admin()->create();
        $updater = User::factory()->admin()->create();
        $campaign = Campaign::factory()->create(['created_by' => $creator, 'updated_by' => $updater]);

        $this->assertTrue($campaign->creator->is($creator));
        $this->assertTrue($campaign->updater->is($updater));
        $this->assertTrue($creator->createdCampaigns->contains($campaign));
        $this->assertTrue($updater->updatedCampaigns->contains($campaign));

        $creator->delete();
        $updater->delete();
        $campaign->refresh();
        $this->assertNull($campaign->created_by);
        $this->assertNull($campaign->updated_by);
    }

    public function test_campaign_soft_delete_and_restore_preserve_row_and_data(): void
    {
        $campaign = Campaign::factory()->create();
        $campaign->delete();
        $this->assertNull(Campaign::find($campaign->id));

        $trashed = Campaign::withTrashed()->findOrFail($campaign->id);
        $this->assertInstanceOf(CarbonImmutable::class, $trashed->deleted_at);
        $trashed->restore();
        $this->assertSame($campaign->slug, Campaign::findOrFail($campaign->id)->slug);
    }

    public function test_factory_states_are_internally_consistent(): void
    {
        $active = Campaign::factory()->active()->create();
        $paused = Campaign::factory()->paused()->create();
        $funded = Campaign::factory()->funded()->create();
        $delivery = Campaign::factory()->aidDelivery()->create();
        $completed = Campaign::factory()->completed()->create();
        $cancelled = Campaign::factory()->cancelled()->create();
        $decorated = Campaign::factory()->featured()->urgent()->priority(7)->expired()->trashed()->create();

        $this->assertNotNull($active->published_at);
        $this->assertSame(CampaignStatus::Paused, $paused->status);
        $this->assertNotNull($paused->pause_reason);
        $this->assertSame($funded->target_amount, $funded->raised_amount);
        $this->assertNotNull($delivery->aid_delivery_started_at);
        $this->assertNotNull($completed->impact_update_ar);
        $this->assertNotNull($completed->impact_update_en);
        $this->assertNotNull($cancelled->cancellation_reason);
        $this->assertTrue($decorated->is_featured);
        $this->assertTrue($decorated->is_urgent);
        $this->assertSame(7, $decorated->priority);
        $this->assertTrue($decorated->expires_at->isPast());
        $this->assertTrue($decorated->trashed());
    }

    public function test_scopes_filter_visibility_and_order_deterministically(): void
    {
        $tiePublicationTime = now()->subDay();
        $publishedActive = Campaign::factory()->active()->featured()->urgent()->priority(5)->create([
            'published_at' => now()->subDays(2),
        ]);
        $newerActiveFirst = Campaign::factory()->active()->priority(5)->create([
            'published_at' => $tiePublicationTime,
        ]);
        $newerActiveSecond = Campaign::factory()->active()->priority(5)->create([
            'published_at' => $tiePublicationTime,
        ]);
        $completed = Campaign::factory()->completed()->priority(9)->create();
        $draft = Campaign::factory()->create();
        $paused = Campaign::factory()->paused()->create();
        $cancelled = Campaign::factory()->cancelled()->create();
        $unpublishedActive = Campaign::factory()->active()->create(['published_at' => null]);
        $funded = Campaign::factory()->funded()->create();
        $aidDelivery = Campaign::factory()->aidDelivery()->create();
        $softDeletedVisible = Campaign::factory()->completed()->trashed()->create();

        $this->assertTrue(Campaign::active()->get()->contains($publishedActive));
        $this->assertTrue(Campaign::published()->get()->contains($publishedActive));
        $this->assertEquals([$publishedActive->id], Campaign::featured()->pluck('id')->all());
        $this->assertEquals([$publishedActive->id], Campaign::urgent()->pluck('id')->all());

        $visibleIds = Campaign::publiclyVisible()->pluck('id');
        $this->assertEqualsCanonicalizing([
            $publishedActive->id,
            $newerActiveFirst->id,
            $newerActiveSecond->id,
            $funded->id,
            $aidDelivery->id,
            $completed->id,
        ], $visibleIds->all());
        $this->assertTrue($visibleIds->contains($publishedActive->id));
        $this->assertTrue($visibleIds->contains($funded->id));
        $this->assertTrue($visibleIds->contains($aidDelivery->id));
        $this->assertTrue($visibleIds->contains($completed->id));
        $this->assertFalse($visibleIds->contains($draft->id));
        $this->assertFalse($visibleIds->contains($paused->id));
        $this->assertFalse($visibleIds->contains($cancelled->id));
        $this->assertFalse($visibleIds->contains($unpublishedActive->id));
        $this->assertFalse($visibleIds->contains($softDeletedVisible->id));

        $this->assertEquals(
            [$completed->id, $newerActiveFirst->id, $newerActiveSecond->id, $publishedActive->id],
            Campaign::whereKey([
                $publishedActive->id,
                $newerActiveFirst->id,
                $newerActiveSecond->id,
                $completed->id,
            ])->inPriorityOrder()->pluck('id')->all()
        );
    }

    public function test_help_application_relationship_is_not_defined(): void
    {
        $this->assertFalse(method_exists(new Campaign, 'helpApplication'));
    }

    public function test_existing_case_routes_and_hard_coded_public_pages_are_unchanged(): void
    {
        $index = Route::getRoutes()->getByName('cases.index');
        $show = Route::getRoutes()->getByName('cases.show');
        $this->assertSame('{locale}/cases', $index->uri());
        $this->assertSame('{locale}/cases/{id}', $show->uri());
        $this->assertSame(DonationCaseController::class.'@index', $index->getActionName());
        $this->assertSame(DonationCaseController::class.'@show', $show->getActionName());

        Campaign::factory()->active()->create(['title_en' => 'Persistent Campaign Must Stay Hidden']);
        $this->get(route('cases.index', ['locale' => 'en']))
            ->assertOk()
            ->assertSee('Heart Surgery for a Sick Child')
            ->assertDontSee('Persistent Campaign Must Stay Hidden');
        $this->get(route('cases.show', ['locale' => 'en', 'id' => 1]))
            ->assertOk()
            ->assertSee('Heart Surgery for a Sick Child')
            ->assertDontSee('Persistent Campaign Must Stay Hidden');
    }
}
