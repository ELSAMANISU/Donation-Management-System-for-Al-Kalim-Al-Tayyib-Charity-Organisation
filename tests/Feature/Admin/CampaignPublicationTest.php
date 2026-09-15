<?php

namespace Tests\Feature\Admin;

use App\Enums\CampaignStatus;
use App\Enums\InternalNotificationType;
use App\Http\Requests\Admin\PublishCampaignRequest;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Category;
use App\Models\HelpApplication;
use App\Models\InternalNotification;
use App\Models\InternalNotificationEvent;
use App\Models\InternalNotificationEventRecipient;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CampaignPublicationService;
use App\Services\InternalNotificationPayload;
use App\Services\InternalNotificationProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CampaignPublicationTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(bool $linked = false, string $extension = 'png'): array
    {
        $this->freezeSecond();
        Storage::fake('campaign_images');
        $actor = User::factory()->admin()->create();
        $category = Category::factory()->create();
        $application = $linked ? HelpApplication::factory()->convertedToCampaign()->create([
            'category_id' => $category->id, 'reviewed_by' => $actor->id, 'decided_by' => $actor->id,
            'category_assigned_by' => $actor->id, 'category_assigned_at' => now()->subDay(),
            'decision_note' => 'Confidential decision note', 'requested_amount' => '50000.00',
        ]) : null;
        $campaign = Campaign::factory()->create(['category_id' => $category->id, 'help_application_id' => $application?->id,
            'target_amount' => '1000.50', 'image_alt_en' => 'Public image', 'image_alt_ar' => 'صورة عامة']);
        $path = 'campaigns/'.$campaign->id.'/'.Str::uuid().'.'.$extension;
        Storage::disk('campaign_images')->put($path, UploadedFile::fake()->image('image.'.$extension)->getContent());
        DB::table('campaigns')->where('id', $campaign->id)->update(['image_path' => $path]);

        return [$actor, $campaign->fresh(), $application];
    }

    public function test_other_administrator_can_publish_but_cannot_access_coordination(): void
    {
        [, $campaign, $application] = $this->fixture(true);
        $other = User::factory()->admin()->create();
        $this->actingAs($other);
        $this->publish($campaign)->assertRedirect(route('admin.campaigns.index'));
        $this->assertSame('active', $campaign->fresh()->status->value);
        DB::table('campaigns')->where('id', $campaign->id)->update(['status' => 'funded', 'raised_amount' => $campaign->target_amount, 'funded_at' => now()]);
        $this->get(route('admin.coordination.entry', ['helpApplication' => $application->reference]))->assertNotFound();
        $this->post(route('admin.coordination.start', ['helpApplication' => $application->reference]))->assertNotFound();
        $this->assertDatabaseCount('assistance_coordinations', 0);
    }

    public function test_ordinary_admin_can_publish_reviewer_null_but_coordination_requires_super_admin(): void
    {
        [$actor, $campaign, $application] = $this->fixture(true);
        DB::table('help_applications')->where('id', $application->id)->update(['reviewed_by' => null]);
        $this->actingAs($actor);
        $this->publish($campaign)->assertRedirect(route('admin.campaigns.index'));
        DB::table('campaigns')->where('id', $campaign->id)->update(['status' => 'funded', 'raised_amount' => $campaign->target_amount, 'funded_at' => now()]);
        $this->get(route('admin.coordination.entry', ['helpApplication' => $application->reference]))->assertNotFound();
        $this->post(route('admin.coordination.start', ['helpApplication' => $application->reference]))->assertNotFound();
        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route('admin.coordination.start', ['helpApplication' => $application->reference]))->assertRedirect();
        $this->assertNull($application->fresh()->reviewed_by);
        $this->assertDatabaseCount('assistance_coordinations', 1);
    }

    private function publish(Campaign $campaign, array $input = [])
    {
        return $this->post(route('admin.campaigns.publish', $campaign), array_merge(['expires_at' => now()->addDay()->format('Y-m-d\TH:i:s')], $input));
    }

    public function test_exact_route_and_no_additional_publication_mutations(): void
    {
        $route = Route::getRoutes()->getByName('admin.campaigns.publish');
        $this->assertSame(['POST'], $route->methods());
        $this->assertSame('admin/campaigns/{campaign}/publish', $route->uri());
        $this->assertSame(['web', 'auth', 'role:admin,super_admin', 'throttle:10,1'], $route->gatherMiddleware());
        $matches = collect(Route::getRoutes())->filter(fn ($route) => str_contains($route->uri(), 'campaigns') && preg_match('/publish|pause|cancel|fund|deliver/', $route->uri()));
        $this->assertCount(1, $matches);
    }

    #[DataProvider('administratorRoles')]
    public function test_manual_publication_changes_exact_fields_and_audit_without_notification(string $role): void
    {
        [$actor, $campaign] = $this->fixture();
        $actor->forceFill(['role' => $role])->save();
        $before = $campaign->getRawOriginal();
        $image = Storage::disk('campaign_images')->get($campaign->image_path);
        $this->travel(1)->seconds();
        $timestamp = now()->toImmutable();
        $expiry = now()->addDay()->toImmutable();
        $this->actingAs($actor);
        $this->publish($campaign, ['status' => 'funded', 'raised_amount' => '800', 'private_story' => 'SECRET', 'priority' => 999])
            ->assertRedirect(route('admin.campaigns.index'))->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Pragma', 'no-cache')->assertSessionHas('status', 'campaign-published');
        $after = $campaign->fresh()->getRawOriginal();
        $changed = array_keys(array_diff_assoc($after, $before));
        sort($changed);
        $this->assertSame(['expires_at', 'published_at', 'status', 'updated_at', 'updated_by'], $changed);
        $this->assertSame('active', $after['status']);
        $this->assertSame($actor->id, $after['updated_by']);
        $audit = AuditLog::sole();
        $this->assertSame('campaign.published', $audit->action);
        $this->assertSame($actor->id, $audit->actor_id);
        $this->assertSame($campaign->getMorphClass(), $audit->subject_type);
        $this->assertSame($campaign->id, $audit->subject_id);
        $this->assertSame(['status' => 'draft', 'published_at' => null, 'expires_at' => null], $audit->old_values);
        $this->assertSame(['status' => 'active', 'published_at' => $timestamp->toISOString(), 'expires_at' => $expiry->toISOString()], $audit->new_values);
        $this->assertSame($image, Storage::disk('campaign_images')->get($campaign->image_path));
        $this->assertDatabaseCount('internal_notification_events', 0);
        $this->assertDatabaseCount('internal_notification_event_recipients', 0);
        $this->assertDatabaseCount('help_applications', 0);
        $this->publish($campaign)->assertNotFound();
        $this->assertDatabaseCount('audit_logs', 1);
        $this->get(route('admin.campaigns.edit', $campaign))->assertForbidden();
        $this->withSession(['status' => 'campaign-published'])->get(route('admin.campaigns.index'))->assertSee(CampaignPublicationService::SUCCESS);
    }

    public static function administratorRoles(): array
    {
        return [['admin'], ['super_admin']];
    }

    public function test_linked_publication_preserves_private_content_and_creates_exact_applicant_intent(): void
    {
        [$actor, $campaign, $application] = $this->fixture(true);
        $before = $application->getRawOriginal();
        $this->travel(1)->seconds();
        DB::enableQueryLog();
        $this->actingAs($actor);
        $this->publish($campaign)->assertRedirect();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            if (str_starts_with($query['query'], 'select') && str_contains($query['query'], 'help_applications')) {
                $this->assertStringNotContainsString('select *', $query['query']);
                foreach (['private_story', 'identity_document', 'full_name', 'email', 'phone'] as $private) {
                    $this->assertStringNotContainsString($private, $query['query']);
                }
            }
        }
        $after = $application->fresh()->getRawOriginal();
        $changed = array_keys(array_diff_assoc($after, $before));
        sort($changed);
        $this->assertSame(['status', 'status_changed_at', 'updated_at', 'updated_by'], $changed);
        $this->assertSame('campaign_active', $after['status']);
        $this->assertSame($campaign->fresh()->getRawOriginal('published_at'), $after['status_changed_at']);
        $this->assertDatabaseCount('audit_logs', 2);
        $audit = AuditLog::where('action', 'help_application.campaign_activated')->sole();
        $this->assertSame(['status' => 'converted_to_campaign'], $audit->old_values);
        $this->assertSame(['status' => 'campaign_active'], $audit->new_values);
        $this->assertSame($application->id, $audit->subject_id);
        $event = InternalNotificationEvent::sole();
        $this->assertSame('help_application_campaign_activated', $event->type->value);
        $intent = InternalNotificationEventRecipient::sole();
        $this->assertSame($application->applicant_id, $intent->recipient_id);
        $this->assertSame('applicant', $intent->audience->value);
        app(InternalNotificationProjector::class)->projectEvent($event);
        app(InternalNotificationProjector::class)->projectEvent($event);
        $this->assertDatabaseCount('internal_notifications', 1);
        $this->assertSame(['application_reference' => $application->reference, 'status' => 'campaign_active'], InternalNotification::sole()->allowlistedData());
        $this->publish($campaign)->assertNotFound();
        $this->assertDatabaseCount('internal_notification_events', 1);
        $this->assertDatabaseCount('internal_notification_event_recipients', 1);
    }

    public function test_super_admin_can_publish_orphaned_linked_application_without_repairing_reviewer(): void
    {
        [, $campaign, $application] = $this->fixture(true);
        $actor = User::factory()->superAdmin()->create();
        DB::table('help_applications')->where('id', $application->id)->update(['reviewed_by' => null]);
        $before = $application->fresh()->getRawOriginal();
        $this->travel(1)->seconds();
        $timestamp = now()->toImmutable();
        $expiry = now()->addDay()->toImmutable();
        $this->actingAs($actor);
        $this->get(route('admin.campaigns.edit', $campaign))->assertOk()->assertSee('name="expires_at"', false);
        $this->publish($campaign)->assertRedirect(route('admin.campaigns.index'))->assertSessionHas('status', 'campaign-published');
        $campaign->refresh();
        $application->refresh();
        $this->assertNull($application->reviewed_by);
        $this->assertSame('active', $campaign->status->value);
        $this->assertSame('campaign_active', $application->status->value);
        $this->assertTrue($campaign->published_at->equalTo($timestamp));
        $this->assertTrue($application->status_changed_at->equalTo($timestamp));
        $this->assertSame($actor->id, $campaign->updated_by);
        $this->assertSame($actor->id, $application->updated_by);
        $changed = array_keys(array_diff_assoc($application->getRawOriginal(), $before));
        sort($changed);
        $this->assertSame(['status', 'status_changed_at', 'updated_at', 'updated_by'], $changed);
        $this->assertDatabaseCount('audit_logs', 2);
        $campaignAudit = AuditLog::where('action', 'campaign.published')->sole();
        $this->assertSame($actor->id, $campaignAudit->actor_id);
        $this->assertSame($campaign->getMorphClass(), $campaignAudit->subject_type);
        $this->assertSame($campaign->id, $campaignAudit->subject_id);
        $this->assertSame(['status' => 'draft', 'published_at' => null, 'expires_at' => null], $campaignAudit->old_values);
        $this->assertSame(['status' => 'active', 'published_at' => $timestamp->toISOString(), 'expires_at' => $expiry->toISOString()], $campaignAudit->new_values);
        $applicationAudit = AuditLog::where('action', 'help_application.campaign_activated')->sole();
        $this->assertSame($actor->id, $applicationAudit->actor_id);
        $this->assertSame($application->getMorphClass(), $applicationAudit->subject_type);
        $this->assertSame($application->id, $applicationAudit->subject_id);
        $this->assertSame(['status' => 'converted_to_campaign'], $applicationAudit->old_values);
        $this->assertSame(['status' => 'campaign_active'], $applicationAudit->new_values);
        $event = InternalNotificationEvent::sole();
        $this->assertSame('help_application_campaign_activated', $event->type->value);
        $intent = InternalNotificationEventRecipient::sole();
        $this->assertSame($application->applicant_id, $intent->recipient_id);
        $this->assertSame('applicant', $intent->audience->value);
        $notification = InternalNotification::sole();
        $this->assertSame($application->applicant_id, $notification->recipient_id);
        $this->assertSame(InternalNotificationType::HelpApplicationCampaignActivated, $notification->type);
        $this->assertSame(['application_reference' => $application->reference, 'status' => 'campaign_active'], $notification->allowlistedData());
        $this->publish($campaign)->assertNotFound();
        $this->assertNull($application->fresh()->reviewed_by);
        $this->assertDatabaseCount('audit_logs', 2);
        $this->assertDatabaseCount('internal_notification_events', 1);
        $this->assertDatabaseCount('internal_notification_event_recipients', 1);
        $this->assertDatabaseCount('internal_notifications', 1);
    }

    #[DataProvider('invalidApplications')]
    public function test_orphaned_linked_application_still_rejects_other_inconsistent_lifecycle_fields(string $field, mixed $value): void
    {
        [, $campaign, $application] = $this->fixture(true);
        DB::table('help_applications')->where('id', $application->id)->update([
            'reviewed_by' => null,
            $field => $value === 'TIME' ? now()->addDay()->toDateTimeString() : $value,
        ]);
        $before = $application->fresh()->getRawOriginal();
        $this->actingAs(User::factory()->superAdmin()->create());
        $this->publish($campaign)->assertNotFound();
        $this->assertSame($before, $application->fresh()->getRawOriginal());
        $this->assertSame(CampaignStatus::Draft, $campaign->fresh()->status);
        foreach (['audit_logs', 'internal_notification_events', 'internal_notification_event_recipients', 'internal_notifications'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_guest_applicant_disabled_and_password_change_boundaries(): void
    {
        [$actor, $campaign] = $this->fixture();
        $this->publish($campaign)->assertNotFound();
        $this->actingAs(User::factory()->user()->create());
        $this->publish($campaign)->assertNotFound();
        $actor->forceFill(['is_active' => false])->save();
        $this->actingAs($actor);
        $this->publish($campaign)->assertNotFound();
        $actor->forceFill(['is_active' => true, 'must_change_password' => true])->save();
        $this->actingAs($actor);
        $this->publish($campaign)->assertNotFound();
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame(CampaignStatus::Draft, $campaign->fresh()->status);
    }

    #[DataProvider('invalidDrafts')]
    public function test_incomplete_and_inconsistent_drafts_fail_closed(string $field, mixed $value): void
    {
        [$actor, $campaign] = $this->fixture();
        DB::table('campaigns')->where('id', $campaign->id)->update([$field => $value === 'TIME' ? now()->toDateTimeString() : $value]);
        $this->actingAs($actor);
        $this->publish($campaign)->assertNotFound();
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function invalidDrafts(): array
    {
        $cases = [];
        foreach (['title_ar' => 255, 'title_en' => 255, 'summary_ar' => 1000, 'summary_en' => 1000, 'story_ar' => 20000, 'story_en' => 20000, 'image_alt_ar' => 255, 'image_alt_en' => 255] as $field => $max) {
            $cases[$field.' blank'] = [$field, '  '];
            $cases[$field.' long'] = [$field, str_repeat('a', $max + 1)];
        }
        foreach (['published_at', 'expires_at', 'paused_at', 'funded_at', 'aid_delivery_started_at', 'completed_at', 'cancelled_at', 'deleted_at'] as $field) {
            $cases[$field] = [$field, 'TIME'];
        }
        foreach (['pause_reason', 'cancellation_reason'] as $field) {
            $cases[$field] = [$field, ''];
        }
        foreach (['active', 'paused', 'funded', 'aid_delivery', 'completed', 'cancelled'] as $status) {
            $cases[$status] = ['status', $status];
        }

        return $cases + ['zero target' => ['target_amount', '0.00'], 'negative target' => ['target_amount', '-1.00'], 'raised' => ['raised_amount', '0.01'], 'no image' => ['image_path', null], 'unmanaged' => ['image_path', '../private.png']];
    }

    #[DataProvider('invalidExpirations')]
    public function test_expiration_validation_is_bilingual_private_and_structurally_safe(mixed $expiration): void
    {
        [$actor, $campaign] = $this->fixture();
        $this->actingAs($actor);
        $response = $this->publish($campaign, ['expires_at' => $expiration, 'private_story' => 'SECRET', 'status' => 'active']);
        $response->assertSessionHasErrors('expires_at', null, 'publication')->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Pragma', 'no-cache');
        $old = session('_old_input');
        $this->assertEmpty(array_diff(array_keys($old), ['expires_at']));
        $this->assertStringNotContainsString('SECRET', json_encode($old));
        $this->assertStringContainsString(' / ', session('errors')->publication->first('expires_at'));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function invalidExpirations(): array
    {
        return [[null], [''], [[]], ['tomorrow'], ['2000-01-01T00:00:00'], ['2027-02-30T00:00:00'], ['<script>secret</script>'], ['999999999999999999999']];
    }

    public function test_present_expiration_fails_and_one_second_future_succeeds_in_application_timezone(): void
    {
        [$actor, $campaign] = $this->fixture();
        config(['app.timezone' => 'Asia/Singapore']);
        $this->actingAs($actor);
        $this->publish($campaign, ['expires_at' => now(config('app.timezone'))->format('Y-m-d\TH:i:s')])->assertSessionHasErrors('expires_at', null, 'publication');
        $this->publish($campaign, ['expires_at' => now(config('app.timezone'))->addSecond()->format('Y-m-d\TH:i:s')])->assertRedirect();
        $this->assertSame(CampaignStatus::Active, $campaign->fresh()->status);
    }

    #[DataProvider('categoryFailures')]
    public function test_inactive_or_deleted_category_blocks_publication(string $field): void
    {
        [$actor, $campaign] = $this->fixture();
        DB::table('categories')->where('id', $campaign->category_id)->update([$field => $field === 'is_active' ? false : now()]);
        $this->actingAs($actor);
        $this->publish($campaign)->assertNotFound();
    }

    public static function categoryFailures(): array
    {
        return [['is_active'], ['deleted_at']];
    }

    #[DataProvider('invalidApplications')]
    public function test_linked_application_incoherence_rolls_back_before_mutation(string $field, mixed $value): void
    {
        [$actor, $campaign, $application] = $this->fixture(true);
        DB::table('help_applications')->where('id', $application->id)->update([$field => $value === 'TIME' ? now()->addDay()->toDateTimeString() : $value]);
        $this->actingAs($actor);
        $this->publish($campaign)->assertNotFound();
        $this->assertSame(CampaignStatus::Draft, $campaign->fresh()->status);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('internal_notification_events', 0);
    }

    public static function invalidApplications(): array
    {
        $cases = [];
        foreach (['submitted_at', 'review_started_at', 'decided_by', 'decided_at', 'decision_note', 'status_changed_at', 'category_id', 'category_assigned_by', 'category_assigned_at', 'open_slot'] as $field) {
            $cases[$field] = [$field, null];
        }

        return $cases + ['invalid reference' => ['reference', 'corrupt-reference'], 'wrong status' => ['status', 'approved'], 'amount' => ['requested_amount', '1.00'], 'appeal' => ['appeal_eligibility_ended_at', 'TIME'], 'future' => ['decided_at', 'TIME'], 'empty note' => ['decision_note', '']];
    }

    #[DataProvider('rollbackStages')]
    public function test_audit_event_and_intent_failures_roll_back_everything(string $stage): void
    {
        [$actor, $campaign, $application] = $this->fixture(true);
        if ($stage === 'campaign_audit' || $stage === 'application_audit') {
            $real = new AuditLogger;
            $this->mock(AuditLogger::class)->shouldReceive('log')->andReturnUsing(function (...$args) use ($stage, $real) {
                if ($args[0] === ($stage === 'campaign_audit' ? 'campaign.published' : 'help_application.campaign_activated')) {
                    throw new RuntimeException('Injected failure');
                }

                return $real->log(...$args);
            });
        } else {
            $class = $stage === 'event' ? InternalNotificationEvent::class : InternalNotificationEventRecipient::class;
            $class::creating(fn () => throw new RuntimeException('Injected failure'));
        }
        try {
            app(CampaignPublicationService::class)->publish($actor, $campaign, now()->addDay()->toImmutable());
            $this->fail('Expected rollback');
        } catch (RuntimeException $e) {
            $this->assertSame('Injected failure', $e->getMessage());
        } finally {
            if (isset($class)) {
                $class::flushEventListeners();
            }
        }
        $this->assertSame('draft', $campaign->fresh()->status->value);
        $this->assertSame('converted_to_campaign', $application->fresh()->status->value);
        foreach (['audit_logs', 'internal_notification_events', 'internal_notification_event_recipients', 'internal_notifications'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public static function rollbackStages(): array
    {
        return [['campaign_audit'], ['application_audit'], ['event'], ['intent']];
    }

    public function test_fresh_locked_actor_and_stale_link_are_reauthorized(): void
    {
        [$actor,$campaign] = $this->fixture();
        DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);
        try {
            app(CampaignPublicationService::class)->publish($actor, $campaign, now()->addDay()->toImmutable());
            $this->fail('Expected concealment');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_complete_and_incomplete_publish_forms_are_separate_and_accessible(): void
    {
        [$actor,$campaign] = $this->fixture();
        $this->actingAs($actor)->get(route('admin.campaigns.edit', $campaign))->assertOk()->assertSee('name="expires_at"', false)->assertSee('aria-describedby="expiration-help"', false)->assertSee('successful publication makes this Campaign public.')->assertDontSee($campaign->image_path);
        Storage::disk('campaign_images')->delete($campaign->image_path);
        $this->get(route('admin.campaigns.edit', $campaign))->assertOk()->assertDontSee('name="expires_at"', false)->assertSee('Valid stored Campaign image');
        $this->publish($campaign)->assertNotFound();
    }

    public function test_corrupt_image_fails_closed(): void
    {
        [$actor,$campaign] = $this->fixture();
        Storage::disk('campaign_images')->put($campaign->image_path, 'not an image');
        $this->actingAs($actor);
        $this->publish($campaign)->assertNotFound();
    }

    #[DataProvider('supportedImages')]
    public function test_supported_image_types_can_publish(string $extension): void
    {
        [$actor,$campaign] = $this->fixture(false, $extension);
        $this->actingAs($actor);
        $this->publish($campaign)->assertRedirect();
        $this->assertSame('active', $campaign->fresh()->status->value);
    }

    public static function supportedImages(): array
    {
        return [['jpg'], ['png'], ['webp']];
    }

    public function test_outbox_is_pending_until_outer_commit_then_projects_once(): void
    {
        [$actor,$campaign] = $this->fixture(true);
        DB::beginTransaction();
        try {
            app(CampaignPublicationService::class)->publish($actor, $campaign, now()->addDay()->toImmutable());
            $this->assertDatabaseCount('internal_notifications', 0);
            $intent = InternalNotificationEventRecipient::sole();
            $this->assertSame('pending', $intent->state->value);
            $this->assertSame(0, $intent->attempts);
            DB::commit();
        } catch (\Throwable $error) {
            DB::rollBack();
            throw $error;
        }
        $this->assertDatabaseCount('internal_notifications', 1);
        $this->assertSame('projected', $intent->fresh()->state->value);
        app(InternalNotificationProjector::class)->projectEvent($intent->event_id);
        $this->assertDatabaseCount('internal_notifications', 1);
    }

    public function test_projection_failure_keeps_publication_and_retry_creates_one_notification(): void
    {
        [$actor,$campaign] = $this->fixture(true);
        InternalNotification::creating(fn () => throw new RuntimeException('Injected projection failure'));
        try {
            app(CampaignPublicationService::class)->publish($actor, $campaign, now()->addDay()->toImmutable());
        } finally {
            InternalNotification::flushEventListeners();
        }
        $this->assertSame('active', $campaign->fresh()->status->value);
        $this->assertDatabaseCount('audit_logs', 2);
        $intent = InternalNotificationEventRecipient::sole();
        $this->assertSame('pending', $intent->state->value);
        $this->assertSame(1, $intent->attempts);
        $this->assertDatabaseCount('internal_notifications', 0);
        $this->travel(60)->seconds();
        $result = app(InternalNotificationProjector::class)->projectReady();
        $this->assertSame(1, $result->projected);
        app(InternalNotificationProjector::class)->projectReady();
        $this->assertDatabaseCount('internal_notifications', 1);
    }

    public function test_stale_campaign_link_and_stale_draft_snapshot_are_concealed(): void
    {
        [$actor,$campaign,$application] = $this->fixture(true);
        $other = HelpApplication::factory()->create();
        DB::table('campaigns')->where('id', $campaign->id)->update(['help_application_id' => $other->id]);
        try {
            app(CampaignPublicationService::class)->publish($actor, $campaign, now()->addDay()->toImmutable());
            $this->fail('Expected stale link concealment');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        DB::table('campaigns')->where('id', $campaign->id)->update(['help_application_id' => $application->id]);
        app(CampaignPublicationService::class)->publish($actor, $campaign, now()->addDay()->toImmutable());
        try {
            app(CampaignPublicationService::class)->publish($actor, $campaign, now()->addDays(2)->toImmutable());
            $this->fail('Expected stale state concealment');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertDatabaseCount('audit_logs', 2);
        $this->assertDatabaseCount('internal_notification_events', 1);
    }

    public function test_foreign_category_and_incoherent_review_chronology_cannot_publish(): void
    {
        [$actor,$campaign,$application] = $this->fixture(true);
        $this->actingAs($actor);
        DB::table('help_applications')->where('id', $application->id)->update(['category_id' => Category::factory()->create()->id]);
        $this->publish($campaign)->assertNotFound();
        DB::table('help_applications')->where('id', $application->id)->update(['category_id' => $campaign->category_id, 'submitted_at' => now()->toDateTimeString()]);
        $this->publish($campaign)->assertNotFound();
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_expiration_is_rechecked_after_lock_and_safe_value_is_the_only_flash_input(): void
    {
        [$actor,$campaign] = $this->fixture();
        $expired = now()->addSecond()->toImmutable();
        $this->travel(2)->seconds();
        try {
            app(CampaignPublicationService::class)->publish($actor, $campaign, $expired);
            $this->fail('Expected expired input concealment');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->actingAs($actor);
        $safe = '2000-01-01T00:00:00';
        $this->publish($campaign, ['expires_at' => $safe, 'secret' => 'never', 'story_en' => 'never'])->assertSessionHasErrors('expires_at', null, 'publication');
        $this->assertSame(['expires_at' => $safe], session('_old_input'));
        $this->assertSame(PublishCampaignRequest::INVALID, session('errors')->publication->first('expires_at'));
    }

    public function test_unknown_campaign_status_and_mismatched_image_content_fail_closed(): void
    {
        [$actor,$campaign] = $this->fixture();
        $this->actingAs($actor);
        DB::table('campaigns')->where('id', $campaign->id)->update(['status' => 'corrupt']);
        $this->publish($campaign)->assertNotFound();
        DB::table('campaigns')->where('id', $campaign->id)->update(['status' => 'draft']);
        Storage::disk('campaign_images')->put($campaign->image_path, UploadedFile::fake()->image('different.jpg')->getContent());
        $this->publish($campaign)->assertNotFound();
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_ordinary_update_cannot_publish_and_publication_has_no_financial_or_storage_side_effect(): void
    {
        [$actor,$campaign] = $this->fixture();
        $this->actingAs($actor);
        $data = ['category_id' => $campaign->category_id];
        foreach (['title_ar', 'title_en', 'summary_ar', 'summary_en', 'story_ar', 'story_en', 'target_amount'] as $field) {
            $data[$field] = $campaign->{$field};
        }
        $this->patch(route('admin.campaigns.update', $campaign), $data + ['status' => 'active', 'published_at' => now(), 'expires_at' => now()->addDay()])->assertRedirect();
        $this->assertSame('draft', $campaign->fresh()->status->value);
        $this->assertNull($campaign->fresh()->published_at);
        $files = Storage::disk('campaign_images')->allFiles();
        Mail::fake();
        Notification::fake();
        Http::preventStrayRequests();
        $this->publish($campaign)->assertRedirect();
        $this->assertSame($files, Storage::disk('campaign_images')->allFiles());
        $this->assertSame('0.00', $campaign->fresh()->raised_amount);
        $this->assertNull($campaign->fresh()->funded_at);
        $this->assertNull($campaign->fresh()->aid_delivery_started_at);
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_publish_throttle_rejects_eleventh_attempt_and_json_guest_is_concealed(): void
    {
        [$actor, $campaign] = $this->fixture();
        $this->postJson(route('admin.campaigns.publish', $campaign), ['expires_at' => 'secret'])->assertNotFound()->assertSessionMissing('_old_input');
        $this->actingAs($actor);
        for ($i = 0; $i < 10; $i++) {
            $this->post(route('admin.campaigns.publish', $campaign), [])->assertSessionHasErrors('expires_at', null, 'publication');
        }
        $this->post(route('admin.campaigns.publish', $campaign), [])->assertStatus(429);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_stale_role_and_password_change_state_are_reauthorized_inside_service(): void
    {
        [$actor, $campaign] = $this->fixture();
        foreach ([['role' => 'user'], ['role' => 'admin', 'must_change_password' => true]] as $state) {
            DB::table('users')->where('id', $actor->id)->update($state);
            try {
                app(CampaignPublicationService::class)->publish($actor, $campaign, now()->addDay()->toImmutable());
                $this->fail('Expected locked actor concealment');
            } catch (HttpException $e) {
                $this->assertSame(404, $e->getStatusCode());
            }
        }
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_subcent_corrupt_balance_is_not_rounded_into_publishable_zero(): void
    {
        [$actor, $campaign] = $this->fixture();
        DB::table('campaigns')->where('id', $campaign->id)->update(['raised_amount' => '0.001']);
        $this->actingAs($actor);
        $this->publish($campaign)->assertNotFound();
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_applicant_status_presentation_supports_campaign_activation(): void
    {
        [$actor, $campaign, $application] = $this->fixture(true);
        app(CampaignPublicationService::class)->publish($actor, $campaign, now()->addDay()->toImmutable());
        $this->actingAs(User::findOrFail($application->applicant_id))->get(route('help-applications.index'))
            ->assertOk()->assertSee('Campaign active / الحملة نشطة')->assertDontSee($campaign->slug)->assertDontSee('Confidential decision note');
    }

    public function test_activation_payload_rejects_extra_or_wrong_status_fields(): void
    {
        $payload = app(InternalNotificationPayload::class);
        $this->expectException(\InvalidArgumentException::class);
        $payload->validate(InternalNotificationType::HelpApplicationCampaignActivated, ['application_reference' => (string) Str::uuid(), 'status' => 'campaign_active', 'slug' => 'private']);
    }
}
