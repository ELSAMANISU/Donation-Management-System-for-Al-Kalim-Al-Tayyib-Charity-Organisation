<?php

namespace Tests\Feature\Admin;

use App\Enums\CampaignStatus;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Category;
use App\Models\User;
use App\Policies\CampaignPolicy;
use App\Services\AuditLogger;
use App\Services\CampaignCreationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CampaignManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_and_normal_user_cannot_use_campaign_administration(): void
    {
        $category = Category::factory()->create();
        foreach (['admin.campaigns.index', 'admin.campaigns.create'] as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
        $this->post(route('admin.campaigns.store'), $this->payload($category))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->user()->create());
        foreach (['admin.campaigns.index', 'admin.campaigns.create'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
        $this->post(route('admin.campaigns.store'), $this->payload($category))->assertForbidden();
    }

    public function test_admin_and_super_admin_can_view_and_create_drafts(): void
    {
        foreach ([User::factory()->admin()->create(), User::factory()->superAdmin()->create()] as $actor) {
            $category = Category::factory()->create();
            $this->actingAs($actor)->get(route('admin.campaigns.index'))->assertOk();
            $this->get(route('admin.campaigns.create'))->assertOk();
            $this->post(route('admin.campaigns.store'), $this->payload($category, ['slug' => 'draft-'.$actor->id]))
                ->assertRedirect(route('admin.campaigns.index'));
        }
    }

    public function test_disabled_and_password_change_pending_administrators_are_blocked(): void
    {
        foreach ([User::factory()->admin()->disabled()->create(), User::factory()->admin()->mustChangePassword()->create()] as $actor) {
            $this->actingAs($actor)->get(route('admin.campaigns.index'))->assertRedirect();
            $this->actingAs($actor)->get(route('admin.campaigns.create'))->assertRedirect();
            $this->actingAs($actor)->post(route('admin.campaigns.store'), $this->payload(Category::factory()->create(), ['slug' => 'blocked-'.$actor->id]))->assertRedirect();
        }
        $this->assertDatabaseCount('campaigns', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'campaign.created']);
    }

    public function test_success_creates_only_an_allowlisted_bilingual_draft_and_safe_audit(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create();
        $payload = $this->payload($category, [
            'slug' => '  Safe Public Draft  ', 'target_amount' => '125.5',
            'status' => 'active', 'raised_amount' => '999.00', 'is_featured' => true,
            'is_urgent' => true, 'priority' => 99, 'published_at' => now(),
            'image_path' => 'campaigns/unsafe.jpg', 'created_by' => 999,
            'private_notes' => 'must-not-appear',
        ]);

        $this->actingAs($admin)->post(route('admin.campaigns.store'), $payload)
            ->assertRedirect(route('admin.campaigns.index'))
            ->assertSessionHas('status', 'campaign-created');

        $campaign = Campaign::query()->sole();
        $this->assertSame('safe-public-draft', $campaign->slug);
        $this->assertSame('125.50', $campaign->target_amount);
        $this->assertSame('0.00', $campaign->raised_amount);
        $this->assertSame(CampaignStatus::Draft, $campaign->status);
        $this->assertFalse($campaign->is_featured);
        $this->assertFalse($campaign->is_urgent);
        $this->assertSame(0, $campaign->priority);
        $this->assertSame($admin->id, $campaign->created_by);
        $this->assertSame($admin->id, $campaign->updated_by);
        foreach (['image_path', 'image_alt_ar', 'image_alt_en', 'expires_at', 'published_at', 'paused_at', 'funded_at', 'aid_delivery_started_at', 'completed_at', 'cancelled_at', 'deleted_at'] as $field) {
            $this->assertNull($campaign->{$field});
        }

        $audit = AuditLog::query()->sole();
        $this->assertSame('campaign.created', $audit->action);
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame($campaign->id, $audit->subject_id);
        $this->assertSame($campaign->getMorphClass(), $audit->subject_type);
        $this->assertNull($audit->old_values);
        $this->assertSame(['category_id', 'slug', 'status', 'target_amount', 'raised_amount', 'is_featured', 'is_urgent', 'priority'], array_keys($audit->new_values));
        $this->assertSame('125.50', $audit->new_values['target_amount']);
        $this->assertSame('0.00', $audit->new_values['raised_amount']);
        $this->assertStringNotContainsString('must-not-appear', json_encode($audit->getAttributes()));
        $this->assertStringNotContainsString($campaign->story_en, json_encode($audit->getAttributes()));
    }

    #[DataProvider('validAmounts')]
    public function test_controller_passes_exact_normalized_amount_from_validated_data_to_service(string $input, string $expected): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create();
        $this->mock(CampaignCreationService::class)
            ->shouldReceive('create')
            ->once()
            ->withArgs(function (User $actor, array $attributes, Request $request) use ($admin, $category, $expected): bool {
                return $actor->is($admin)
                    && $attributes['category_id'] === $category->id
                    && $attributes['target_amount'] === $expected
                    && $request->validated('target_amount') === $expected
                    && $request->safe()->only(['target_amount']) === ['target_amount' => $expected];
            })
            ->andReturn(new Campaign);

        $this->actingAs($admin)->post(route('admin.campaigns.store'), $this->payload($category, [
            'slug' => 'amount-'.str_replace('.', '-', $input), 'target_amount' => $input,
        ]))->assertRedirect(route('admin.campaigns.index'))->assertSessionDoesntHaveErrors();
        $this->assertDatabaseCount('campaigns', 0);
    }

    public static function validAmounts(): array
    {
        return [
            ['100', '100.00'],
            ['100.5', '100.50'],
            ['100.50', '100.50'],
            ['0.01', '0.01'],
            ['9999999999999999.99', '9999999999999999.99'],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_amounts_create_nothing(mixed $input): void
    {
        $admin = User::factory()->admin()->create();
        $this->mock(CampaignCreationService::class)->shouldNotReceive('create');
        $this->actingAs($admin)->post(route('admin.campaigns.store'), $this->payload(Category::factory()->create(), ['target_amount' => $input]))
            ->assertSessionHasErrors('target_amount');
        $this->assertDatabaseCount('campaigns', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function invalidAmounts(): array
    {
        return [[0], ['0'], ['0.00'], ['-1'], ['+1'], ['1e3'], ['1,000'], ['1.234'], [['1']], ['10000000000000000.00']];
    }

    public function test_category_and_slug_validation_include_inactive_deleted_and_soft_deleted_conflicts(): void
    {
        $admin = User::factory()->admin()->create();
        $inactive = Category::factory()->inactive()->create();
        $deleted = Category::factory()->trashed()->create();
        Campaign::factory()->trashed()->create(['slug' => 'reserved']);

        foreach ([[$inactive, 'new-one', 'category_id'], [$deleted, 'new-two', 'category_id'], [Category::factory()->create(), 'reserved', 'slug']] as [$category, $slug, $field]) {
            $this->actingAs($admin)->post(route('admin.campaigns.store'), $this->payload($category, ['slug' => $slug]))->assertSessionHasErrors($field);
        }
        $this->assertDatabaseCount('campaigns', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_service_rechecks_stale_category_and_actor(): void
    {
        $admin = User::factory()->admin()->create();
        foreach (['inactive', 'deleted'] as $state) {
            $category = Category::factory()->create();
            DB::table('categories')->where('id', $category->id)->update($state === 'inactive' ? ['is_active' => false] : ['deleted_at' => now()]);
            try {
                app(CampaignCreationService::class)->create($admin, $this->payload($category, ['slug' => 'stale-category-'.$state]), Request::create('/admin/campaigns', 'POST'));
                $this->fail('Stale category should be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('category_id', $exception->errors());
            }
            $this->assertDatabaseCount('campaigns', 0);
            $this->assertDatabaseMissing('audit_logs', ['action' => 'campaign.created']);
        }

        foreach ([['is_active' => false], ['must_change_password' => true]] as $state) {
            DB::table('users')->where('id', $admin->id)->update(['is_active' => true, 'must_change_password' => false]);
            $staleActor = $admin->fresh();
            DB::table('users')->where('id', $admin->id)->update($state);
            try {
                app(CampaignCreationService::class)->create($staleActor, $this->payload(Category::factory()->create(), ['slug' => 'stale-actor-'.count($state)]), Request::create('/admin/campaigns', 'POST'));
                $this->fail('Stale actor should be rejected.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
            $this->assertDatabaseCount('campaigns', 0);
            $this->assertDatabaseMissing('audit_logs', ['action' => 'campaign.created']);
        }
    }

    public function test_audit_failure_rolls_back_campaign(): void
    {
        $admin = User::factory()->admin()->create();
        $this->mock(AuditLogger::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('Audit failed.'));
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($admin)->post(route('admin.campaigns.store'), $this->payload(Category::factory()->create()));
            $this->fail('Expected audit failure was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit failed.', $exception->getMessage());
        }
        $this->assertDatabaseCount('campaigns', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'campaign.created']);
    }

    public function test_simulated_slug_constraint_failure_is_safe_and_unrelated_sql_errors_are_not_masked(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create();
        DB::statement("CREATE TRIGGER concurrent_campaign_slug BEFORE INSERT ON campaigns WHEN NEW.slug = 'race-slug' BEGIN SELECT RAISE(ABORT, 'UNIQUE constraint failed: campaigns.slug'); END");

        $this->actingAs($admin)->from(route('admin.campaigns.create'))
            ->post(route('admin.campaigns.store'), $this->payload($category, ['slug' => 'race-slug']))
            ->assertRedirect(route('admin.campaigns.create'))->assertSessionHasErrors('slug');

        DB::statement('DROP TRIGGER concurrent_campaign_slug');
        DB::statement("CREATE TRIGGER unrelated_campaign_failure BEFORE INSERT ON campaigns BEGIN SELECT RAISE(ABORT, 'NOT NULL constraint failed: campaigns.title_en'); END");
        $this->withoutExceptionHandling();
        $this->expectException(QueryException::class);
        $this->post(route('admin.campaigns.store'), $this->payload($category, [
            'slug' => 'unrelated-error',
            'title_en' => 'Public copy mentioning campaigns_slug_unique must not affect classification',
        ]));
    }

    public function test_index_and_create_controls_are_authorized_independently(): void
    {
        $admin = User::factory()->admin()->create();
        $this->mock(CampaignPolicy::class)
            ->shouldReceive('viewAny')->andReturn(true)
            ->shouldReceive('create')->andReturn(false);

        $this->actingAs($admin)->get(route('admin.campaigns.index'))->assertOk()
            ->assertDontSee(route('admin.campaigns.create'));
        $this->get(route('admin.campaigns.create'))->assertForbidden();
    }

    public function test_index_projection_order_pagination_escaping_and_soft_deleted_category(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create(['name_en' => '<b>unsafe category</b>']);
        Campaign::factory()->count(16)->for($category)->create();
        $newest = Campaign::factory()->for($category)->create(['title_en' => '<script>unsafe</script>']);
        $hidden = Campaign::factory()->trashed()->for($category)->create(['title_en' => 'Hidden campaign']);
        $category->delete();

        $this->actingAs($admin)->get(route('admin.campaigns.index'))->assertOk()
            ->assertViewHas('campaigns', fn ($items) => $items->perPage() === 15 && $items->total() === 17 && $items->first()->id === $newest->id)
            ->assertSee($newest->title_en)->assertDontSee($newest->title_en, false)
            ->assertSee($category->name_en)->assertDontSee($category->name_en, false)
            ->assertDontSee($hidden->title_en)->assertDontSee($newest->story_en)
            ->assertSee('page=2', false);
    }

    public function test_index_renders_every_bilingual_status_label_and_bilingual_slug_heading(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create();
        foreach (CampaignStatus::cases() as $status) {
            Campaign::factory()->for($category)->create(['status' => $status]);
        }

        $this->actingAs($admin)->get(route('admin.campaigns.index'))->assertOk()
            ->assertSee('Slug /')
            ->assertSee('المعرّف')
            ->assertSee('Draft / مسودة')
            ->assertSee('Active / نشطة')
            ->assertSee('Paused / متوقفة مؤقتًا')
            ->assertSee('Fully Funded / مكتملة التمويل')
            ->assertSee('Aid Delivery / تسليم المساعدة')
            ->assertSee('Completed / مكتملة')
            ->assertSee('Cancelled / ملغاة');
    }

    public function test_malformed_old_input_returns_usable_accessible_form_without_persistence(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create();
        $response = $this->actingAs($admin)->from(route('admin.campaigns.create'))->post(route('admin.campaigns.store'), $this->payload($category, [
            'category_id' => ['invalid'],
            'title_ar' => ['invalid'],
            'target_amount' => ['invalid'],
        ]));
        $response->assertRedirect(route('admin.campaigns.create'))->assertSessionHasErrors(['category_id', 'title_ar', 'target_amount']);

        $this->get(route('admin.campaigns.create'))->assertOk()
            ->assertSee('id="category_id-error"', false)
            ->assertSee('aria-describedby="category_id-error"', false)
            ->assertSee('id="title_ar-error"', false)
            ->assertSee('aria-describedby="title_ar-error"', false)
            ->assertSee('id="target_amount-error"', false)
            ->assertSee('aria-describedby="target_amount-error"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertDontSee('value="Array"', false)
            ->assertDontSee('>Array</textarea>', false);
        $this->assertDatabaseCount('campaigns', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'campaign.created']);
    }

    public function test_create_form_has_language_direction_error_associations_and_focus_styles(): void
    {
        $admin = User::factory()->admin()->create();
        Category::factory()->create();
        $this->actingAs($admin)->from(route('admin.campaigns.create'))->post(route('admin.campaigns.store'), [])->assertSessionHasErrors();

        $this->get(route('admin.campaigns.create'))->assertOk()
            ->assertSee('id="title_ar"', false)->assertSee('lang="ar" dir="rtl"', false)
            ->assertSee('id="summary_ar"', false)->assertSee('id="story_ar"', false)
            ->assertSee('id="title_en"', false)->assertSee('lang="en" dir="ltr"', false)
            ->assertSee('id="slug"', false)->assertSee('id="target_amount"', false)
            ->assertSee('aria-describedby="slug-error"', false)
            ->assertSee('id="slug-error"', false)
            ->assertSee('focus:ring-2', false);
    }

    public function test_create_page_empty_state_navigation_and_public_prototype_isolation(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('admin.campaigns.create'))->assertOk()->assertSee('No active categories are available')->assertDontSee('method="POST" action="'.route('admin.campaigns.store').'"', false);
        $this->get(route('admin.dashboard'))->assertSee(route('admin.campaigns.index'));
        $category = Category::factory()->create();
        $this->get(route('admin.campaigns.create'))->assertSee(route('admin.campaigns.store'))->assertDontSee('image_path')->assertDontSee('published_at');
        Campaign::factory()->active()->for($category)->create(['title_en' => 'Database campaign marker']);
        $this->get(route('cases.index', ['locale' => 'en']))->assertDontSee('Database campaign marker');
    }

    /** @param array<string, mixed> $overrides */
    private function payload(Category $category, array $overrides = []): array
    {
        return array_merge([
            'category_id' => $category->id, 'slug' => 'safe-draft',
            'title_ar' => 'عنوان عام', 'title_en' => 'Public Title',
            'summary_ar' => 'ملخص عام', 'summary_en' => 'Public summary',
            'story_ar' => 'قصة عامة آمنة', 'story_en' => 'Privacy-safe public story',
            'target_amount' => '900.00',
        ], $overrides);
    }
}
