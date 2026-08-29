<?php

namespace Tests\Feature\Admin;

use App\Enums\CampaignStatus;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Category;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CampaignUpdateService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CampaignUpdateTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('validAmounts')]
    public function test_update_controller_passes_exact_normalized_amount_to_service(string $input, string $expected): void
    {
        $admin = User::factory()->admin()->create();
        $campaign = Campaign::factory()->create();
        $this->mock(CampaignUpdateService::class)->shouldReceive('update')->once()->withArgs(
            fn (User $actor, Campaign $bound, array $attributes, Request $request) => $actor->is($admin) && $bound->is($campaign)
                && $attributes['target_amount'] === $expected && $request->validated('target_amount') === $expected
                && $request->safe()->only(['target_amount']) === ['target_amount' => $expected]
        )->andReturn($campaign);
        $this->actingAs($admin)->patch(route('admin.campaigns.update', $campaign), $this->payload($campaign, ['target_amount' => $input]))->assertSessionDoesntHaveErrors();
    }

    public static function validAmounts(): array
    {
        return [['100', '100.00'], ['100.5', '100.50'], ['100.50', '100.50'], ['0.01', '0.01'], ['9999999999999999.99', '9999999999999999.99']];
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_update_amount_never_calls_service(mixed $input): void
    {
        $admin = User::factory()->admin()->create();
        $campaign = Campaign::factory()->create();
        $this->mock(CampaignUpdateService::class)->shouldNotReceive('update');
        $this->actingAs($admin)->patch(route('admin.campaigns.update', $campaign), $this->payload($campaign, ['target_amount' => $input]))->assertSessionHasErrors('target_amount');
        $this->assertDatabaseMissing('audit_logs', ['action' => 'campaign.updated']);
    }

    public static function invalidAmounts(): array
    {
        return [['0'], ['-1'], ['+1'], ['1e3'], ['1,000'], ['1.234'], [['1']], ['10000000000000000.00']];
    }

    public function test_authorization_binding_and_status_eligibility(): void
    {
        $draft = Campaign::factory()->create();
        $draftSnapshot = $this->campaignSnapshot($draft);
        $this->get(route('admin.campaigns.edit', $draft))->assertRedirect(route('login'));
        $this->patch(route('admin.campaigns.update', $draft), $this->payload($draft, ['title_en' => 'Rejected guest']))->assertRedirect(route('login'));
        $user = User::factory()->user()->create();
        $this->actingAs($user)->get(route('admin.campaigns.edit', $draft))->assertForbidden();
        $this->patch(route('admin.campaigns.update', $draft), $this->payload($draft, ['title_en' => 'Rejected user']))->assertForbidden();
        $this->assertSame($draftSnapshot, $this->campaignSnapshot($draft));
        $this->assertDatabaseMissing('audit_logs', ['action' => 'campaign.updated', 'subject_id' => $draft->id]);

        foreach ([User::factory()->admin()->create(), User::factory()->superAdmin()->create()] as $index => $actor) {
            $campaign = Campaign::factory()->create(['title_en' => 'Original '.$index]);
            $this->actingAs($actor)->get(route('admin.campaigns.edit', $campaign))->assertOk();
            $this->actingAs($actor)->patch(route('admin.campaigns.update', $campaign), $this->payload($campaign, ['title_en' => 'Updated '.$index]))
                ->assertRedirect(route('admin.campaigns.index'));
            $this->assertSame('Updated '.$index, $campaign->fresh()->title_en);
            $this->assertDatabaseHas('audit_logs', ['action' => 'campaign.updated', 'actor_id' => $actor->id, 'subject_id' => $campaign->id]);
        }
        foreach (array_filter(CampaignStatus::cases(), fn ($status) => $status !== CampaignStatus::Draft) as $status) {
            $campaign = Campaign::factory()->create(['status' => $status]);
            $admin = User::factory()->admin()->create();
            $snapshot = $this->campaignSnapshot($campaign);
            $this->actingAs($admin)->get(route('admin.campaigns.edit', $campaign))->assertForbidden();
            $this->actingAs($admin)->patch(route('admin.campaigns.update', $campaign), $this->payload($campaign, ['title_en' => 'Rejected']))->assertForbidden();
            $this->assertSame($snapshot, $this->campaignSnapshot($campaign));
            $this->assertDatabaseMissing('audit_logs', ['action' => 'campaign.updated', 'subject_id' => $campaign->id]);
        }

        foreach ([User::factory()->admin()->create(['is_active' => false]), User::factory()->admin()->mustChangePassword()->create()] as $ineligibleAdmin) {
            $this->actingAs($ineligibleAdmin)->get(route('admin.campaigns.edit', $draft))->assertRedirect();
            $this->actingAs($ineligibleAdmin)->patch(route('admin.campaigns.update', $draft), $this->payload($draft, ['title_en' => 'Rejected admin']))->assertRedirect();
            $this->assertSame($draftSnapshot, $this->campaignSnapshot($draft));
            $this->assertDatabaseMissing('audit_logs', ['action' => 'campaign.updated', 'subject_id' => $draft->id]);
        }
        $trashed = Campaign::factory()->trashed()->create();
        $admin = User::factory()->admin()->create();
        $trashedSnapshot = $this->campaignSnapshot($trashed);
        $this->actingAs($admin)->get('/admin/campaigns/'.$trashed->slug.'/edit')->assertNotFound();
        $this->actingAs($admin)->patch('/admin/campaigns/'.$trashed->slug, $this->payload($trashed, ['title_en' => 'Rejected']))->assertNotFound();
        $this->assertSame($trashedSnapshot, $this->campaignSnapshot($trashed));
        $this->assertDatabaseMissing('audit_logs', ['action' => 'campaign.updated', 'subject_id' => $trashed->id]);
        $this->actingAs($admin)->get('/admin/campaigns/missing/edit')->assertNotFound();
        $this->actingAs($admin)->patch('/admin/campaigns/missing', $this->payload($draft))->assertNotFound();
    }

    public function test_success_updates_only_editable_values_and_writes_safe_audit(): void
    {
        $creator = User::factory()->admin()->create();
        $actor = User::factory()->admin()->create();
        $oldCategory = Category::factory()->create();
        $newCategory = Category::factory()->create();
        $campaign = Campaign::factory()->for($oldCategory)->create(['created_by' => $creator, 'updated_by' => $creator, 'target_amount' => '900.00']);
        $original = $campaign->replicate();
        $originalCreatedAt = $campaign->created_at;

        $this->actingAs($actor)->patch(route('admin.campaigns.update', $campaign), $this->payload($campaign, [
            'category_id' => $newCategory->id, 'title_en' => 'Changed title', 'story_ar' => 'قصة عامة جديدة', 'target_amount' => '125.5',
            'slug' => 'injected', 'status' => 'active', 'raised_amount' => '99.00', 'priority' => 99, 'created_by' => 999, 'published_at' => now(),
        ]))->assertRedirect(route('admin.campaigns.index'))->assertSessionHas('status', 'campaign-updated');

        $campaign->refresh();
        $this->assertSame($newCategory->id, $campaign->category_id);
        $this->assertSame('Changed title', $campaign->title_en);
        $this->assertSame('125.50', $campaign->target_amount);
        $this->assertSame($original->slug, $campaign->slug);
        $this->assertSame(CampaignStatus::Draft, $campaign->status);
        $this->assertSame('0.00', $campaign->raised_amount);
        $this->assertSame($creator->id, $campaign->created_by);
        $this->assertTrue($campaign->created_at->equalTo($originalCreatedAt));
        $this->assertSame($actor->id, $campaign->updated_by);
        $this->assertSame(0, $campaign->priority);
        $this->assertNull($campaign->published_at);

        $audit = AuditLog::query()->sole();
        $this->assertSame('campaign.updated', $audit->action);
        $this->assertSame($actor->id, $audit->actor_id);
        $this->assertSame($campaign->id, $audit->subject_id);
        $this->assertSame($campaign->getMorphClass(), $audit->subject_type);
        $keys = ['category_id', 'target_amount', 'status', 'raised_amount', 'changed_fields'];
        $this->assertSame($keys, array_keys($audit->old_values));
        $this->assertSame($keys, array_keys($audit->new_values));
        $this->assertSame(['category_id', 'title_en', 'story_ar', 'target_amount'], $audit->new_values['changed_fields']);
        $this->assertSame($audit->old_values['changed_fields'], $audit->new_values['changed_fields']);
        $this->assertSame('900.00', $audit->old_values['target_amount']);
        $this->assertSame('125.50', $audit->new_values['target_amount']);
        $this->assertStringNotContainsString('Changed title', json_encode($audit->getAttributes()));
        $this->assertStringNotContainsString('injected', json_encode($audit->getAttributes()));
    }

    public function test_equivalent_noop_preserves_updater_timestamp_and_audit(): void
    {
        try {
            $this->travelTo('2026-08-30 10:00:00');
            $creator = User::factory()->admin()->create();
            $campaign = Campaign::factory()->create(['updated_by' => $creator, 'target_amount' => '100.00']);
            $timestamp = $campaign->updated_at;
            $this->travelTo('2026-08-31 10:00:00');
            $actor = User::factory()->admin()->create();
            $this->actingAs($actor)->patch(route('admin.campaigns.update', $campaign), $this->payload($campaign, ['target_amount' => '100']))
                ->assertSessionHas('status', 'campaign-unchanged');
            $campaign->refresh();
            $this->assertSame($creator->id, $campaign->updated_by);
            $this->assertTrue($campaign->updated_at->equalTo($timestamp));
            $this->assertDatabaseMissing('audit_logs', ['action' => 'campaign.updated']);
        } finally {
            $this->travelBack();
        }
    }

    public function test_validation_and_array_old_input_are_safe(): void
    {
        $admin = User::factory()->admin()->create();
        $campaign = Campaign::factory()->create();
        $this->actingAs($admin)->from(route('admin.campaigns.edit', $campaign))->patch(route('admin.campaigns.update', $campaign), $this->payload($campaign, [
            'category_id' => ['bad'], 'title_ar' => ['bad'], 'target_amount' => ['bad'],
        ]))->assertSessionHasErrors(['category_id', 'title_ar', 'target_amount']);
        $this->get(route('admin.campaigns.edit', $campaign))->assertOk()->assertDontSee('value="Array"', false)->assertSee('aria-invalid="true"', false);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'campaign.updated']);
    }

    public function test_all_bilingual_content_fields_are_required_without_persistence(): void
    {
        $admin = User::factory()->admin()->create();
        $campaign = Campaign::factory()->create();
        $snapshot = $this->campaignSnapshot($campaign);
        $fields = ['title_ar', 'title_en', 'summary_ar', 'summary_en', 'story_ar', 'story_en'];

        $this->actingAs($admin)->patch(route('admin.campaigns.update', $campaign), $this->payload($campaign, array_fill_keys($fields, '')))
            ->assertSessionHasErrors($fields);
        $this->assertSame($snapshot, $this->campaignSnapshot($campaign));
        $this->assertDatabaseMissing('audit_logs', ['action' => 'campaign.updated', 'subject_id' => $campaign->id]);
    }

    #[DataProvider('overlongContentFields')]
    public function test_bilingual_content_length_limits_reject_without_persistence(string $field, int $limit): void
    {
        $admin = User::factory()->admin()->create();
        $campaign = Campaign::factory()->create();
        $snapshot = $this->campaignSnapshot($campaign);

        $this->actingAs($admin)->patch(route('admin.campaigns.update', $campaign), $this->payload($campaign, [$field => str_repeat('x', $limit + 1)]))
            ->assertSessionHasErrors($field);
        $this->assertSame($snapshot, $this->campaignSnapshot($campaign));
        $this->assertDatabaseMissing('audit_logs', ['action' => 'campaign.updated', 'subject_id' => $campaign->id]);
    }

    public static function overlongContentFields(): array
    {
        return [
            ['title_ar', 255], ['title_en', 255],
            ['summary_ar', 1000], ['summary_en', 1000],
            ['story_ar', 20000], ['story_en', 20000],
        ];
    }

    #[DataProvider('invalidCategorySelections')]
    public function test_invalid_category_selections_reject_without_persistence(string $state): void
    {
        $admin = User::factory()->admin()->create();
        $campaign = Campaign::factory()->create();
        $category = match ($state) {
            'inactive' => Category::factory()->inactive()->create(),
            'deleted' => Category::factory()->trashed()->create(),
            default => null,
        };
        $snapshot = $this->campaignSnapshot($campaign);
        $selection = $category?->id;

        $this->actingAs($admin)->patch(route('admin.campaigns.update', $campaign), $this->payload($campaign, ['category_id' => $selection]))
            ->assertSessionHasErrors('category_id');
        $this->assertSame($snapshot, $this->campaignSnapshot($campaign));
        $this->assertDatabaseMissing('audit_logs', ['action' => 'campaign.updated', 'subject_id' => $campaign->id]);
    }

    public static function invalidCategorySelections(): array
    {
        return [['missing'], ['inactive'], ['deleted']];
    }

    #[DataProvider('authorizationStaleStates')]
    public function test_ineligible_actor_and_non_draft_stale_states_are_authorization_failures(string $case): void
    {
        $admin = User::factory()->admin()->create();
        $campaign = Campaign::factory()->create();
        $attributes = $this->payload($campaign, ['title_en' => 'Must not save']);
        if ($case === 'campaign') {
            DB::table('campaigns')->where('id', $campaign->id)->update(['status' => CampaignStatus::Active->value]);
        } elseif ($case === 'inactive_actor') {
            DB::table('users')->where('id', $admin->id)->update(['is_active' => false]);
        } else {
            DB::table('users')->where('id', $admin->id)->update(['must_change_password' => true]);
        }
        $snapshot = $this->campaignSnapshot($campaign);

        $this->expectException(AuthorizationException::class);
        try {
            app(CampaignUpdateService::class)->update($admin, $campaign, $attributes, Request::create('/admin/campaigns/'.$campaign->slug, 'PATCH'));
        } finally {
            $this->assertSame($snapshot, $this->campaignSnapshot($campaign));
            $this->assertDatabaseMissing('audit_logs', ['action' => 'campaign.updated', 'subject_id' => $campaign->id]);
        }
    }

    public static function authorizationStaleStates(): array
    {
        return [['campaign'], ['inactive_actor'], ['password_actor']];
    }

    public function test_soft_deleted_stale_campaign_is_a_model_not_found_failure(): void
    {
        $admin = User::factory()->admin()->create();
        $campaign = Campaign::factory()->create();
        $attributes = $this->payload($campaign, ['title_en' => 'Must not save']);
        DB::table('campaigns')->where('id', $campaign->id)->update(['deleted_at' => now()]);
        $snapshot = $this->campaignSnapshot($campaign);

        $this->expectException(ModelNotFoundException::class);
        try {
            app(CampaignUpdateService::class)->update($admin, $campaign, $attributes, Request::create('/admin/campaigns/'.$campaign->slug, 'PATCH'));
        } finally {
            $this->assertSame($snapshot, $this->campaignSnapshot($campaign));
            $this->assertDatabaseMissing('audit_logs', ['action' => 'campaign.updated', 'subject_id' => $campaign->id]);
        }
    }

    #[DataProvider('unavailableCategoryStates')]
    public function test_stale_unavailable_category_is_a_category_validation_failure(string $state): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create();
        $campaign = Campaign::factory()->for($category)->create();
        $attributes = $this->payload($campaign, ['title_en' => 'Must not save']);
        DB::table('categories')->where('id', $category->id)->update($state === 'inactive' ? ['is_active' => false] : ['deleted_at' => now()]);
        $snapshot = $this->campaignSnapshot($campaign);

        try {
            app(CampaignUpdateService::class)->update($admin, $campaign, $attributes, Request::create('/admin/campaigns/'.$campaign->slug, 'PATCH'));
            $this->fail('Expected category validation failure.');
        } catch (ValidationException $exception) {
            $this->assertSame(['category_id'], array_keys($exception->errors()));
        }
        $this->assertSame($snapshot, $this->campaignSnapshot($campaign));
        $this->assertDatabaseMissing('audit_logs', ['action' => 'campaign.updated', 'subject_id' => $campaign->id]);
    }

    public static function unavailableCategoryStates(): array
    {
        return [['inactive'], ['deleted']];
    }

    public function test_unexpected_raised_balance_is_a_target_amount_validation_failure(): void
    {
        $admin = User::factory()->admin()->create();
        $campaign = Campaign::factory()->create();
        $attributes = $this->payload($campaign, ['target_amount' => '999.00']);
        DB::table('campaigns')->where('id', $campaign->id)->update(['raised_amount' => '1.00']);
        $snapshot = $this->campaignSnapshot($campaign);

        try {
            app(CampaignUpdateService::class)->update($admin, $campaign, $attributes, Request::create('/admin/campaigns/'.$campaign->slug, 'PATCH'));
            $this->fail('Expected target amount validation failure.');
        } catch (ValidationException $exception) {
            $this->assertSame(['target_amount'], array_keys($exception->errors()));
        }
        $this->assertSame($snapshot, $this->campaignSnapshot($campaign));
        $this->assertSame('1.00', Campaign::findOrFail($campaign->id)->raised_amount);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'campaign.updated', 'subject_id' => $campaign->id]);
    }

    public function test_audit_failure_rolls_back_every_change(): void
    {
        try {
            $this->travelTo('2026-08-30 10:00:00');
            $originalActor = User::factory()->admin()->create();
            $updatingActor = User::factory()->admin()->create();
            $originalCategory = Category::factory()->create();
            $newCategory = Category::factory()->create();
            $campaign = Campaign::factory()->for($originalCategory)->create(['updated_by' => $originalActor, 'target_amount' => '900.00']);
            $snapshot = $this->campaignSnapshot($campaign);
            $this->travelTo('2026-08-31 10:00:00');
            $this->mock(AuditLogger::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('Audit failed.'));
            $thrown = false;

            try {
                app(CampaignUpdateService::class)->update($updatingActor, $campaign, $this->payload($campaign, [
                    'category_id' => $newCategory->id,
                    'title_ar' => 'Changed Arabic title', 'title_en' => 'Changed English title',
                    'summary_ar' => 'Changed Arabic summary', 'summary_en' => 'Changed English summary',
                    'story_ar' => 'Changed Arabic story', 'story_en' => 'Changed English story',
                    'target_amount' => '999.99',
                ]), Request::create('/admin/campaigns/'.$campaign->slug, 'PATCH'));
            } catch (RuntimeException $exception) {
                $thrown = true;
                $this->assertSame('Audit failed.', $exception->getMessage());
            }
            $this->assertTrue($thrown, 'The forced audit exception was not thrown.');
            $this->assertSame($snapshot, $this->campaignSnapshot($campaign));
            $this->assertSame($originalActor->id, Campaign::findOrFail($campaign->id)->updated_by);
            $this->assertDatabaseMissing('audit_logs', ['action' => 'campaign.updated', 'subject_id' => $campaign->id]);
        } finally {
            $this->travelBack();
        }
    }

    public function test_text_only_update_audits_field_names_without_public_text(): void
    {
        $admin = User::factory()->admin()->create();
        $campaign = Campaign::factory()->create(['target_amount' => '900.00']);
        $categoryId = $campaign->category_id;

        $this->actingAs($admin)->patch(route('admin.campaigns.update', $campaign), $this->payload($campaign, [
            'title_ar' => 'New Arabic public title',
            'story_en' => 'New English public story',
        ]))->assertRedirect(route('admin.campaigns.index'));

        $campaign->refresh();
        $audit = AuditLog::query()->where('action', 'campaign.updated')->sole();
        $this->assertSame(['title_ar', 'story_en'], $audit->old_values['changed_fields']);
        $this->assertSame(['title_ar', 'story_en'], $audit->new_values['changed_fields']);
        foreach (['title_ar', 'title_en', 'summary_ar', 'summary_en', 'story_ar', 'story_en'] as $field) {
            $this->assertArrayNotHasKey($field, $audit->old_values);
            $this->assertArrayNotHasKey($field, $audit->new_values);
        }
        $this->assertSame($categoryId, $campaign->category_id);
        $this->assertSame('900.00', $campaign->target_amount);
        $encodedAudit = json_encode(['old_values' => $audit->old_values, 'new_values' => $audit->new_values]);
        $this->assertStringNotContainsString('New Arabic public title', $encodedAudit);
        $this->assertStringNotContainsString('New English public story', $encodedAudit);
        $this->assertStringNotContainsString('Campaign', $encodedAudit);
    }

    #[DataProvider('unavailableCategoryStates')]
    public function test_unavailable_current_category_ui_requires_an_eligible_replacement(string $state): void
    {
        $admin = User::factory()->admin()->create();
        $current = Category::factory()->create(['name_en' => 'Unavailable Current']);
        $replacement = Category::factory()->create(['name_en' => 'Eligible Replacement']);
        $campaign = Campaign::factory()->for($current)->create();
        if ($state === 'inactive') {
            DB::table('categories')->where('id', $current->id)->update(['is_active' => false]);
        } else {
            $current->delete();
        }

        $response = $this->actingAs($admin)->get(route('admin.campaigns.edit', $campaign));
        $response->assertOk()
            ->assertSee('The current Category is unavailable; choose an eligible Category before saving.')
            ->assertSee('Eligible Replacement')
            ->assertDontSee('Unavailable Current')
            ->assertDontSee('value="'.$replacement->id.'" selected', false);

        $snapshot = $this->campaignSnapshot($campaign);
        $this->actingAs($admin)->patch(route('admin.campaigns.update', $campaign), $this->payload($campaign, ['category_id' => '']))
            ->assertSessionHasErrors('category_id');
        $this->assertSame($snapshot, $this->campaignSnapshot($campaign));
        $this->assertDatabaseMissing('audit_logs', ['action' => 'campaign.updated', 'subject_id' => $campaign->id]);
    }

    public function test_edit_page_without_eligible_categories_has_bilingual_empty_state_and_no_save_form(): void
    {
        $admin = User::factory()->admin()->create();
        $current = Category::factory()->inactive()->create();
        $campaign = Campaign::factory()->for($current)->create();

        $this->actingAs($admin)->get(route('admin.campaigns.edit', $campaign))
            ->assertOk()
            ->assertSee('No eligible Categories are available. This draft cannot be saved.')
            ->assertSee('لا توجد فئات متاحة ولا يمكن حفظ المسودة.', false)
            ->assertDontSee('<form method="POST" action="'.route('admin.campaigns.update', $campaign).'"', false)
            ->assertSee(route('admin.campaigns.index'));
    }

    public function test_index_edit_controls_and_edit_page_ui_are_safe(): void
    {
        $admin = User::factory()->admin()->create();
        $draft = Campaign::factory()->create(['slug' => 'safe-slug', 'title_en' => '<script>x</script>']);
        $active = Campaign::factory()->active()->create();
        $this->actingAs($admin)->get(route('admin.campaigns.index'))->assertSee(route('admin.campaigns.edit', $draft))->assertDontSee(route('admin.campaigns.edit', $active));
        $this->get(route('admin.campaigns.edit', $draft))->assertOk()->assertSee('safe-slug')->assertDontSee('<script>x</script>', false)
            ->assertSee('lang="ar" dir="rtl"', false)->assertSee('lang="en" dir="ltr"', false)->assertDontSee('name="slug"', false)->assertDontSee('published_at');
        $this->assertSame('{locale}/cases/{id}', app('router')->getRoutes()->getByName('cases.show')->uri());
    }

    /** @param array<string,mixed> $overrides */
    private function payload(Campaign $campaign, array $overrides = []): array
    {
        return array_merge(['category_id' => $campaign->category_id, 'title_ar' => $campaign->title_ar, 'title_en' => $campaign->title_en, 'summary_ar' => $campaign->summary_ar, 'summary_en' => $campaign->summary_en, 'story_ar' => $campaign->story_ar, 'story_en' => $campaign->story_en, 'target_amount' => $campaign->target_amount], $overrides);
    }

    /** @return array<string,mixed> */
    private function campaignSnapshot(Campaign $campaign): array
    {
        return Campaign::withTrashed()->findOrFail($campaign->id)->getAttributes();
    }
}
