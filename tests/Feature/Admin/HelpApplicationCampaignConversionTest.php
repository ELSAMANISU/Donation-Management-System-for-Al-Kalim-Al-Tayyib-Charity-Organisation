<?php

namespace Tests\Feature\Admin;

use App\Enums\HelpApplicationStatus;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Category;
use App\Models\HelpApplication;
use App\Models\User;
use App\Services\CampaignApplicationAmount;
use App\Services\CampaignUpdateService;
use App\Services\HelpApplicationCampaignConversionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class HelpApplicationCampaignConversionTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->freezeSecond();
        $actor = User::factory()->admin()->create();
        $application = HelpApplication::factory()->approved()->assignedTo(Category::factory()->create(), $actor)->create([
            'reviewed_by' => $actor->id, 'decided_by' => $actor->id, 'decided_at' => now(),
            'status_changed_at' => now(), 'decision_note' => '<script>private decision</script>',
        ]);

        return [$actor, $application->refresh()];
    }

    private function payload(): array
    {
        return ['slug' => 'public-draft', 'title_ar' => 'عنوان عام', 'title_en' => 'Public title',
            'summary_ar' => 'ملخص عام', 'summary_en' => 'Public summary', 'story_ar' => 'قصة عامة مستقلة',
            'story_en' => 'Deliberately authored public story.', 'target_amount' => '1000.50'];
    }

    private function url(HelpApplication $application): string
    {
        return route('admin.help-applications.decided.convert-to-campaign', $application->reference);
    }

    private function assertNoEffects(): void
    {
        foreach (['campaigns', 'audit_logs', 'internal_notifications', 'internal_notification_events', 'internal_notification_event_recipients'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_success_changes_exact_application_fields_and_creates_exact_private_draft_and_audits(): void
    {
        [$actor, $application] = $this->fixture();
        $before = $application->getRawOriginal();
        $this->travel(1)->minutes();
        Bus::fake();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        Storage::shouldReceive('disk')->never();
        Log::spy();
        $response = $this->actingAs($actor)->post($this->url($application), $this->payload() + [
            'category_id' => Category::factory()->create()->id, 'private_story' => 'NEVER COPY', 'published_at' => now()->toDateTimeString(),
            'help_application_id' => 999, 'raised_amount' => '200', 'is_featured' => true,
        ]);
        $campaign = Campaign::sole();
        $response->assertRedirect(route('admin.campaigns.edit', $campaign))->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Pragma', 'no-cache')
            ->assertSessionHas('status', 'campaign-created-from-help-application');
        $this->assertSame($application->id, $campaign->help_application_id);
        $this->assertSame($application->category_id, $campaign->category_id);
        foreach ($this->payload() as $field => $value) {
            $this->assertSame($value, $campaign->{$field});
        }
        $this->assertSame('draft', $campaign->status->value);
        $this->assertSame('0.00', $campaign->raised_amount);
        $this->assertFalse($campaign->is_featured);
        $this->assertFalse($campaign->is_urgent);
        $this->assertSame(0, $campaign->priority);
        foreach (['image_path', 'image_alt_ar', 'image_alt_en', 'expires_at', 'published_at', 'paused_at', 'pause_reason', 'funded_at', 'aid_delivery_started_at', 'completed_at', 'cancelled_at', 'cancellation_reason', 'impact_update_ar', 'impact_update_en', 'deleted_at'] as $field) {
            $this->assertNull($campaign->{$field});
        }
        $this->assertSame($actor->id, $campaign->created_by);
        $this->assertSame($actor->id, $campaign->updated_by);
        $this->assertTrue($campaign->created_at->equalTo($campaign->updated_at));
        $application->refresh();
        foreach ($before as $field => $value) {
            if (! in_array($field, ['status', 'status_changed_at', 'updated_by', 'updated_at'], true)) {
                $this->assertSame($value, $application->getRawOriginal($field), $field);
            }
        }
        $this->assertSame(HelpApplicationStatus::ConvertedToCampaign, $application->status);
        $this->assertSame($actor->id, $application->updated_by);
        $this->assertTrue($application->status_changed_at->equalTo($application->updated_at));
        $audits = AuditLog::orderBy('id')->get();
        $this->assertCount(2, $audits);
        $this->assertSame('campaign.created_from_help_application', $audits[0]->action);
        $this->assertNull($audits[0]->old_values);
        $this->assertSame(['category_id' => $application->category_id, 'slug' => 'public-draft', 'status' => 'draft', 'target_amount' => '1000.50', 'raised_amount' => '0.00', 'is_featured' => false, 'is_urgent' => false, 'priority' => 0], $audits[0]->new_values);
        $this->assertSame('help_application.converted_to_campaign', $audits[1]->action);
        $this->assertSame(['status' => 'approved'], $audits[1]->old_values);
        $this->assertSame(['status' => 'converted_to_campaign'], $audits[1]->new_values);
        foreach ($audits as $audit) {
            $this->assertSame($actor->id, $audit->actor_id);
        }
        $this->assertSame($campaign->id, (int) $audits[0]->subject_id);
        $this->assertSame($application->id, (int) $audits[1]->subject_id);
        $this->assertSame($application->id, $campaign->helpApplication->id);
        $this->assertSame($campaign->id, $application->campaign->id);
        $this->assertArrayNotHasKey('help_application', $campaign->toArray());
        $this->assertArrayNotHasKey('help_application_id', $campaign->toArray());
        $this->assertStringNotContainsString('private decision', $campaign->toJson());
        $this->assertSame(0, Campaign::publiclyVisible()->count());
        foreach (['internal_notifications', 'internal_notification_events', 'internal_notification_event_recipients'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Bus::assertNothingDispatched();
        Mail::assertNothingSent();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
        $this->get(route('admin.campaigns.edit', $campaign))->assertOk()->assertSee('Campaign draft created successfully. The Campaign remains unpublished.');
    }

    public static function invalidInputs(): array
    {
        $cases = [];
        foreach (['slug' => 160, 'title_ar' => 255, 'title_en' => 255, 'summary_ar' => 1000, 'summary_en' => 1000, 'story_ar' => 20000, 'story_en' => 20000] as $field => $max) {
            foreach (['empty' => '', 'array' => ['private'], 'number' => 12, 'long' => str_repeat('x', $max + 1)] as $name => $value) {
                $cases[$field.' '.$name] = [$field, $value];
            }
        }
        foreach (['0', '0.00', '-1', '1e2', '01', '1,000', '.5', '1.', '1.001', '1000.51', '10000000000000000', '', [], 1, 1.5] as $i => $value) {
            $cases['amount '.$i] = ['target_amount', $value];
        }

        return $cases;
    }

    #[DataProvider('invalidInputs')]
    public function test_validation_boundaries_never_flash_long_copy_or_unexpected_input(string $field, mixed $value): void
    {
        [$actor, $application] = $this->fixture();
        $payload = $this->payload();
        $payload[$field] = $value;
        $response = $this->actingAs($actor)->post($this->url($application), $payload + ['private_story' => 'PRIVATE', 'email' => 'secret@example.test']);
        $response->assertRedirect(route('admin.help-applications.decided.show', $application->reference))->assertSessionHasErrors($field)
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Pragma', 'no-cache');
        $old = session('_old_input', []);
        $this->assertSame([], array_diff(array_keys($old), ['slug', 'title_ar', 'title_en', 'target_amount']));
        $this->assertStringNotContainsString('PRIVATE', json_encode($old));
        $page = $this->get(route('admin.help-applications.decided.show', $application->reference))->assertOk();
        $page->assertSee('aria-describedby="'.$field.'-error"', false)->assertSee('id="'.$field.'-error"', false);
        foreach (['summary_en', 'summary_ar', 'story_en', 'story_ar'] as $name) {
            $this->assertMatchesRegularExpression('/<textarea[^>]*name="'.$name.'"[^>]*><\/textarea>/', $page->getContent());
        }
        $this->assertNoEffects();
    }

    public static function validAmounts(): array
    {
        return [['0.01', '0.01'], ['1', '1.00'], ['12.3', '12.30'], ['1000.50', '1000.50']];
    }

    #[DataProvider('validAmounts')]
    public function test_exact_amount_normalization_and_canonical_slug(string $input, string $expected): void
    {
        [$actor, $application] = $this->fixture();
        $payload = $this->payload();
        $payload['target_amount'] = $input;
        $payload['slug'] = ' Public Draft ';
        $this->actingAs($actor)->post($this->url($application), $payload)->assertRedirect();
        $this->assertSame($expected, Campaign::sole()->target_amount);
        $this->assertSame('public-draft', Campaign::sole()->slug);
    }

    public function test_decimal_comparison_preserves_precision_at_decimal_bounds(): void
    {
        $this->assertSame('9999999999999999.99', CampaignApplicationAmount::canonical('9999999999999999.99'));
        $this->assertTrue(CampaignApplicationAmount::exceeds('9999999999999999.99', '9999999999999999.98'));
        $this->assertFalse(CampaignApplicationAmount::exceeds('9999999999999999.98', '9999999999999999.99'));
        $this->assertFalse(CampaignApplicationAmount::exceeds('10.00', '10.00'));
        $this->assertTrue(CampaignApplicationAmount::exceeds('100.00', '99.99'));
    }

    public static function invalidLifecycle(): array
    {
        $cases = [];
        foreach (HelpApplicationStatus::cases() as $status) {
            if ($status !== HelpApplicationStatus::Approved) {
                $cases[$status->value] = ['status', $status->value];
            }
        }
        foreach (['open_slot', 'submitted_at', 'review_started_at', 'decided_by', 'decided_at', 'decision_note', 'status_changed_at', 'category_id', 'category_assigned_by', 'category_assigned_at'] as $field) {
            $cases[$field] = [$field, null];
        }
        $cases['appeal'] = ['appeal_eligibility_ended_at', '2026-09-01 00:00:00'];
        $cases['incoherent decision'] = ['status_changed_at', '2000-01-01 00:00:00'];
        $cases['incoherent review'] = ['review_started_at', '2099-01-01 00:00:00'];
        $cases['incoherent submission'] = ['submitted_at', '2099-01-01 00:00:00'];
        $cases['incoherent category'] = ['category_assigned_at', '2000-01-01 00:00:00'];
        $cases['corrupt note'] = ['decision_note', 'not ciphertext'];

        return $cases;
    }

    #[DataProvider('invalidLifecycle')]
    public function test_each_invalid_lifecycle_state_is_concealed_before_validation(string $field, mixed $value): void
    {
        [$actor, $application] = $this->fixture();
        DB::table('help_applications')->where('id', $application->id)->update([$field => $value]);
        $this->actingAs($actor)->post($this->url($application), [])->assertNotFound()->assertSessionMissing('_old_input');
        $this->assertNoEffects();
    }

    public function test_access_boundaries_and_missing_references(): void
    {
        [$actor, $application] = $this->fixture();
        $this->post($this->url($application), [])->assertNotFound();
        foreach ([User::factory()->user()->create(), User::factory()->admin()->disabled()->create(), User::factory()->admin()->mustChangePassword()->create(), User::factory()->admin()->create()] as $other) {
            $this->actingAs($other)->post($this->url($application), [])->assertNotFound()->assertSessionMissing('_old_input');
        }
        $this->actingAs($actor);
        foreach (['invalid', '999', (string) Str::uuid()] as $ref) {
            $this->post('/admin/help-applications/decided/'.$ref.'/convert-to-campaign', [])->assertNotFound();
        }
        $this->assertNoEffects();
    }

    public static function historicalCategories(): array
    {
        return [[false, false], [true, false], [true, true]];
    }

    #[DataProvider('historicalCategories')]
    public function test_super_admin_can_convert_orphan_with_historical_category(bool $inactive, bool $deleted): void
    {
        [$actor, $application] = $this->fixture();
        $category = $application->category;
        if ($inactive) {
            $category->is_active = false;
            $category->save();
        } if ($deleted) {
            $category->delete();
        }
        DB::table('help_applications')->where('id', $application->id)->update(['reviewed_by' => null]);
        $this->actingAs($actor)->post($this->url($application), $this->payload())->assertNotFound();
        $super = User::factory()->superAdmin()->create();
        $this->actingAs($super)->post($this->url($application), $this->payload())->assertRedirect();
        $this->assertSame($category->id, Campaign::sole()->category_id);
        $this->assertSame($super->id, Campaign::sole()->created_by);
    }

    public function test_repetition_opposite_actor_and_soft_deleted_relationship_cannot_create_second_draft(): void
    {
        [$actor, $application] = $this->fixture();
        $this->actingAs($actor)->post($this->url($application), $this->payload())->assertRedirect();
        $this->post($this->url($application), $this->payload())->assertNotFound();
        $this->actingAs(User::factory()->superAdmin()->create())->post($this->url($application), $this->payload())->assertNotFound();
        Campaign::sole()->delete();
        DB::table('help_applications')->where('id', $application->id)->update(['status' => 'approved', 'status_changed_at' => $application->decided_at]);
        $this->post($this->url($application), $this->payload())->assertNotFound();
        $this->assertDatabaseCount('campaigns', 1);
        $this->assertDatabaseCount('audit_logs', 2);
        $this->assertTrue($application->refresh()->campaign->trashed());
    }

    public function test_soft_deleted_slug_uses_generic_duplicate_message(): void
    {
        [$actor, $application] = $this->fixture();
        Campaign::factory()->create(['slug' => 'public-draft'])->delete();
        $this->actingAs($actor)->post($this->url($application), $this->payload())->assertSessionHasErrors(['slug' => 'This campaign slug is already in use. / مُعرّف الحملة مستخدم بالفعل.']);
        $this->assertSame(HelpApplicationStatus::Approved, $application->refresh()->status);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_fresh_service_checks_amount_actor_and_lifecycle_after_stale_form(): void
    {
        [$actor, $application] = $this->fixture();
        $service = app(HelpApplicationCampaignConversionService::class);
        DB::table('help_applications')->where('id', $application->id)->update(['requested_amount' => '1.00']);
        try {
            $service->convert($actor, $application->reference, $this->payload());
            $this->fail('Expected amount failure');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('target_amount', $e->errors());
        }
        DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);
        try {
            $service->convert($actor, $application->reference, $this->payload());
            $this->fail('Expected actor failure');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        DB::table('users')->where('id', $actor->id)->update(['is_active' => true]);
        DB::table('help_applications')->where('id', $application->id)->update(['category_assigned_at' => null]);
        try {
            $service->convert($actor, $application->reference, $this->payload());
            $this->fail('Expected lifecycle failure');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertNoEffects();
    }

    public static function auditFailures(): array
    {
        return [['campaign.created_from_help_application'], ['help_application.converted_to_campaign']];
    }

    #[DataProvider('auditFailures')]
    public function test_either_audit_failure_rolls_back_everything(string $action): void
    {
        [$actor, $application] = $this->fixture();
        $before = $application->getRawOriginal();
        $dispatcher = AuditLog::getEventDispatcher();
        AuditLog::setEventDispatcher(clone $dispatcher);
        AuditLog::creating(function ($audit) use ($action) {
            if ($audit->action === $action) {
                throw new \RuntimeException('Synthetic audit failure');
            }
        });
        try {
            try {
                app(HelpApplicationCampaignConversionService::class)->convert($actor, $application->reference, $this->payload());
                $this->fail('Expected failure');
            } catch (\RuntimeException $e) {
                $this->assertSame('Synthetic audit failure', $e->getMessage());
            }
        } finally {
            AuditLog::setEventDispatcher($dispatcher);
        }
        $this->assertSame($before, $application->refresh()->getRawOriginal());
        $this->assertNoEffects();
    }

    public static function sqlFailures(): array
    {
        return [
            'sqlite slug' => ['23000', 19, 'UNIQUE constraint failed: campaigns.slug', 'slug'],
            'mysql slug' => ['23000', 1062, "Duplicate entry for key 'campaigns_slug_unique'", 'slug'],
            'sqlite link' => ['23000', 19, 'UNIQUE constraint failed: campaigns.help_application_id', 'link'],
            'mysql link' => ['23000', 1062, "Duplicate entry for key 'campaigns_help_application_id_unique'", 'link'],
            'unrelated unique' => ['23000', 19, 'UNIQUE constraint failed: campaigns.title_en', 'sql'],
            'foreign key' => ['23000', 19, 'FOREIGN KEY constraint failed', 'sql'],
        ];
    }

    #[DataProvider('sqlFailures')]
    public function test_simulated_constraint_race_diagnostics_do_not_mask_unrelated_sql(string $state, int $code, string $message, string $expected): void
    {
        [$actor, $application] = $this->fixture();
        $pdo = new \PDOException($message);
        $pdo->errorInfo = [$state, $code, $message];
        $exception = new QueryException('sqlite', 'insert into campaigns', [], $pdo);
        $dispatcher = Campaign::getEventDispatcher();
        Campaign::setEventDispatcher(clone $dispatcher);
        Campaign::creating(fn () => throw $exception);
        try {
            try {
                app(HelpApplicationCampaignConversionService::class)->convert($actor, $application->reference, $this->payload());
                $this->fail('Expected exception');
            } catch (ValidationException $e) {
                $this->assertSame('slug', $expected);
                $this->assertArrayHasKey('slug', $e->errors());
            } catch (HttpException $e) {
                $this->assertSame('link', $expected);
                $this->assertSame(404, $e->getStatusCode());
            } catch (QueryException $e) {
                $this->assertSame('sql', $expected);
                $this->assertSame($exception, $e);
            }
        } finally {
            Campaign::setEventDispatcher($dispatcher);
        }
        $this->assertNoEffects();
        $this->assertSame(HelpApplicationStatus::Approved, $application->refresh()->status);
    }

    public function test_linked_edit_preserves_historical_category_and_amount_limit_and_ignores_attachment_input(): void
    {
        [$actor, $application] = $this->fixture();
        $this->actingAs($actor)->post($this->url($application), $this->payload())->assertRedirect();
        $campaign = Campaign::sole();
        $category = $application->category;
        $category->is_active = false;
        $category->save();
        $category->delete();
        $page = $this->get(route('admin.campaigns.edit', $campaign))->assertOk()->assertSee('Inherited category')->assertDontSee('name="category_id"', false);
        $payload = $this->payload();
        $payload['title_en'] = 'Edited public title';
        $payload['target_amount'] = '999.00';
        $payload['category_id'] = Category::factory()->create()->id;
        $payload['help_application_id'] = null;
        $this->patch(route('admin.campaigns.update', $campaign), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $campaign->refresh();
        $this->assertSame('Edited public title', $campaign->title_en);
        $this->assertSame($category->id, $campaign->category_id);
        $this->assertSame($application->id, $campaign->help_application_id);
        $payload['target_amount'] = '1000.51';
        $this->patch(route('admin.campaigns.update', $campaign), $payload)->assertSessionHasErrors('target_amount');
        $this->assertSame('999.00', $campaign->refresh()->target_amount);
        app(CampaignUpdateService::class)->update($actor, $campaign, array_replace($payload, ['target_amount' => '900.00']), request());
        $this->assertSame($category->id, $campaign->refresh()->category_id);
        $campaign->fill(['help_application_id' => null]);
        $this->assertSame($application->id, $campaign->help_application_id);
        $manual = new Campaign;
        $manual->fill(['help_application_id' => $application->id]);
        $this->assertNull($manual->help_application_id);
    }

    public function test_link_unique_constraint_includes_soft_deleted_campaigns_and_foreign_key_restricts_delete(): void
    {
        [$actor, $application] = $this->fixture();
        app(HelpApplicationCampaignConversionService::class)->convert($actor, $application->reference, $this->payload())->campaign->delete();
        try {
            DB::table('help_applications')->where('id', $application->id)->delete();
            $this->fail('Expected restricted delete');
        } catch (QueryException) {
            $this->assertDatabaseHas('help_applications', ['id' => $application->id]);
        }
        try {
            Campaign::factory()->create(['help_application_id' => $application->id]);
            $this->fail('Expected unique constraint');
        } catch (QueryException) {
            $this->assertDatabaseCount('campaigns', 1);
        }
    }

    public function test_forward_migration_and_rollback_preserve_existing_manual_campaign_data(): void
    {
        $manual = Campaign::factory()->create();
        $before = $manual->refresh()->getRawOriginal();
        unset($before['help_application_id']);
        $migration = require database_path('migrations/2026_09_10_000000_add_help_application_id_to_campaigns_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('campaigns', 'help_application_id'));
        $this->assertSame($before, $manual->refresh()->getRawOriginal());
        $migration->up();
        $this->assertTrue(Schema::hasColumn('campaigns', 'help_application_id'));
        $this->assertNull($manual->refresh()->help_application_id);
        $after = $manual->getRawOriginal();
        unset($after['help_application_id']);
        $this->assertSame($before, $after);
    }

    public function test_all_public_copy_maximum_lengths_succeed(): void
    {
        [$actor, $application] = $this->fixture();
        // SQLite NUMERIC affinity cannot preserve DECIMAL(18,2) at its upper bound; precision is tested as strings separately.
        $payload = $this->payload();
        foreach (['slug' => 160, 'title_ar' => 255, 'title_en' => 255, 'summary_ar' => 1000, 'summary_en' => 1000, 'story_ar' => 20000, 'story_en' => 20000] as $field => $limit) {
            $payload[$field] = str_repeat('a', $limit);
        }
        $this->actingAs($actor)->post($this->url($application), $payload)->assertSessionHasNoErrors()->assertRedirect();
        $campaign = Campaign::sole();
        foreach ($payload as $field => $value) {
            $this->assertSame($value, $campaign->{$field});
        }
    }

    #[DataProvider('invalidLifecycle')]
    public function test_locked_service_independently_rejects_each_invalid_lifecycle_state(string $field, mixed $value): void
    {
        [$actor, $application] = $this->fixture();
        DB::table('help_applications')->where('id', $application->id)->update([$field => $value]);
        try {
            app(HelpApplicationCampaignConversionService::class)->convert($actor, $application->reference, $this->payload());
            $this->fail('Expected concealed lifecycle failure');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        } catch (ModelNotFoundException $exception) {
            $this->assertSame(HelpApplication::class, $exception->getModel());
        }
        $this->assertNoEffects();
    }

    public function test_deterministic_request_to_service_amount_change_uses_safe_private_validation_redirect(): void
    {
        [$actor, $application] = $this->fixture();
        $armed = true;
        // A retrieved callback mutates the single SQLite connection after the request snapshot, before the service reads it.
        $dispatcher = HelpApplication::getEventDispatcher();
        HelpApplication::setEventDispatcher(clone $dispatcher);
        HelpApplication::retrieved(function ($snapshot) use (&$armed, $application): void {
            if ($armed && $snapshot->id === $application->id && $snapshot->getRawOriginal('requested_amount') !== null) {
                $armed = false;
                DB::table('help_applications')->where('id', $application->id)->update(['requested_amount' => '1.00']);
            }
        });
        try {
            $this->actingAs($actor)->post($this->url($application), $this->payload() + ['private_story' => 'NEVER FLASH'])
                ->assertRedirect(route('admin.help-applications.decided.show', $application->reference))
                ->assertSessionHasErrors('target_amount')->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Pragma', 'no-cache');
        } finally {
            HelpApplication::setEventDispatcher($dispatcher);
        }
        $this->assertFalse($armed);
        $this->assertSame([], array_diff(array_keys(session('_old_input')), ['slug', 'target_amount', 'title_ar', 'title_en']));
        $this->assertNoEffects();
    }

    public function test_linked_draft_with_inconsistent_publication_state_cannot_be_edited(): void
    {
        [$actor, $application] = $this->fixture();
        $campaign = app(HelpApplicationCampaignConversionService::class)->convert($actor, $application->reference, $this->payload())->campaign;
        DB::table('campaigns')->where('id', $campaign->id)->update(['published_at' => now()]);
        $this->actingAs($actor)->patch(route('admin.campaigns.update', $campaign), array_replace($this->payload(), ['title_en' => 'Forged edit']))->assertNotFound();
        $this->assertSame('Public title', $campaign->refresh()->title_en);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_throttle_is_ten_attempts(): void
    {
        [$actor, $application] = $this->fixture();
        $this->actingAs($actor);
        for ($i = 0; $i < 10; $i++) {
            $this->post($this->url($application), [])->assertSessionHasErrors();
        }
        $this->post($this->url($application), [])->assertStatus(429);
        $this->assertNoEffects();
    }
}
