<?php

namespace Tests\Feature\Admin;

use App\Enums\HelpApplicationStatus;
use App\Models\AuditLog;
use App\Models\HelpApplication;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\HelpApplicationReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class HelpApplicationStartReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_route_is_exact_post_uuid_rate_limited_admin_surface_and_only_mutation(): void
    {
        $routes = collect(app('router')->getRoutes())->filter(fn ($route) => str_starts_with((string) $route->getName(), 'admin.help-applications.'));
        $route = $routes->firstWhere('action.as', 'admin.help-applications.start-review');

        $this->assertNotNull($route);
        $this->assertSame(['POST'], $route->methods());
        $this->assertSame('admin/help-applications/{helpApplication}/start-review', $route->uri());
        $this->assertSame('[\\da-fA-F]{8}-[\\da-fA-F]{4}-[\\da-fA-F]{4}-[\\da-fA-F]{4}-[\\da-fA-F]{12}', $route->wheres['helpApplication']);
        foreach (['web', 'auth', 'role:admin,super_admin', 'throttle:10,1'] as $middleware) {
            $this->assertContains($middleware, $route->gatherMiddleware());
        }
        $mutations = $routes->filter(fn ($candidate) => array_intersect($candidate->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']));
        $this->assertCount(1, $mutations);
    }

    public function test_guest_user_disabled_and_password_change_administrators_cannot_start_review(): void
    {
        $application = $this->pendingApplication();
        $url = route('admin.help-applications.start-review', $application->reference);
        $this->post($url)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->user()->create())->post($url)->assertForbidden();
        $this->actingAs(User::factory()->admin()->disabled()->create())->post($url)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->admin()->mustChangePassword()->create())->post($url)->assertRedirect(route('password.change.required.edit'));
        $this->assertSame(HelpApplicationStatus::Pending, $application->fresh()->status);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_active_admin_and_super_admin_each_can_start_a_pending_review(): void
    {
        foreach ([User::factory()->admin()->create(), User::factory()->superAdmin()->create()] as $actor) {
            $application = $this->pendingApplication();
            $this->actingAs($actor)->post(route('admin.help-applications.start-review', $application->reference))
                ->assertRedirect(route('admin.help-applications.index'))
                ->assertSessionHas('status', 'help-application-review-started');
            $this->assertSame($actor->getKey(), $application->fresh()->reviewed_by);
        }
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_transition_changes_only_exact_lifecycle_fields_with_one_shared_timestamp(): void
    {
        Carbon::setTestNow('2026-09-01 12:34:56');
        $actor = User::factory()->admin()->create();
        $applicant = User::factory()->user()->create();
        $application = $this->pendingApplication([
            'applicant_id' => $applicant->getKey(), 'updated_by' => $applicant->getKey(),
            'identity_document_number' => 'PRIVATE-ID', 'identity_blind_index' => str_repeat('a', 64),
        ]);
        $before = (array) DB::table('help_applications')->where('id', $application->getKey())->first();

        $this->actingAs($actor)->post(route('admin.help-applications.start-review', $application->reference))->assertRedirect();
        $after = (array) DB::table('help_applications')->where('id', $application->getKey())->first();
        $expectedChanged = ['status', 'reviewed_by', 'review_started_at', 'status_changed_at', 'updated_by'];
        foreach ($before as $key => $value) {
            if (! in_array($key, $expectedChanged, true)) {
                $this->assertSame($value, $after[$key], $key.' changed unexpectedly');
            }
        }
        $this->assertSame('under_review', $after['status']);
        $this->assertSame($actor->getKey(), $after['reviewed_by']);
        $this->assertSame($actor->getKey(), $after['updated_by']);
        $this->assertSame($after['review_started_at'], $after['status_changed_at']);
        $this->assertSame(1, $after['open_slot']);
        $this->assertNull($after['category_id']);
    }

    #[DataProvider('malformedPendingTimestampProvider')]
    public function test_malformed_pending_transition_timestamp_fails_closed_without_side_effects(?string $statusChangedAt): void
    {
        $actor = User::factory()->admin()->create();
        $applicant = User::factory()->user()->create();
        $submittedAt = '2026-09-01 08:00:00';
        $application = $this->pendingApplication([
            'applicant_id' => $applicant->getKey(),
            'full_name' => 'PRIVATE MALFORMED APPLICANT',
            'submitted_at' => $submittedAt,
            'status_changed_at' => $statusChangedAt,
            'updated_by' => $applicant->getKey(),
        ]);
        $before = (array) DB::table('help_applications')->where('id', $application->getKey())->first();
        $notificationTables = ['internal_notification_events', 'internal_notification_event_recipients', 'internal_notifications'];
        $notificationCounts = collect($notificationTables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()]);

        $response = $this->actingAs($actor)->post(route('admin.help-applications.start-review', $application->reference));

        $response->assertNotFound()->assertDontSee('PRIVATE MALFORMED APPLICANT');
        $after = (array) DB::table('help_applications')->where('id', $application->getKey())->first();
        $this->assertSame($before, $after);
        $this->assertSame('pending', $after['status']);
        $this->assertNull($after['reviewed_by']);
        $this->assertNull($after['review_started_at']);
        $this->assertSame($statusChangedAt, $after['status_changed_at']);
        $this->assertSame($applicant->getKey(), $after['updated_by']);
        $this->assertDatabaseCount('audit_logs', 0);
        foreach ($notificationCounts as $table => $count) {
            $this->assertSame($count, DB::table($table)->count());
        }
    }

    /** @return array<string, array{0: string|null}> */
    public static function malformedPendingTimestampProvider(): array
    {
        return [
            'missing status change timestamp' => [null],
            'status change timestamp differs from submission' => ['2026-09-01 08:00:01'],
        ];
    }

    public function test_audit_is_exact_privacy_safe_and_no_notifications_or_storage_operations_occur(): void
    {
        Notification::fake();
        Queue::fake();
        Storage::shouldReceive('disk')->never();
        $actor = User::factory()->admin()->create(['name' => 'PRIVATE REVIEWER']);
        $application = $this->pendingApplication(['private_story' => 'PRIVATE STORY']);
        $notificationTables = ['internal_notification_events', 'internal_notification_event_recipients', 'internal_notifications'];
        $before = collect($notificationTables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()]);

        $this->actingAs($actor)->post(route('admin.help-applications.start-review', $application->reference));

        $audit = AuditLog::query()->sole();
        $this->assertSame('help_application.review_started', $audit->action);
        $this->assertSame($actor->getKey(), $audit->actor_id);
        $this->assertSame($application->getKey(), $audit->subject_id);
        $this->assertSame(['status' => 'pending', 'open_slot' => true], $audit->old_values);
        $this->assertSame(['status' => 'under_review', 'open_slot' => true], $audit->new_values);
        $serialized = json_encode([$audit->old_values, $audit->new_values]);
        foreach (['PRIVATE REVIEWER', 'PRIVATE STORY', 'reviewed_by', 'timestamp', 'identity'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }
        $after = collect($notificationTables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()]);
        $this->assertSame($before->all(), $after->all());
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_audit_failure_rolls_back_every_transition_field(): void
    {
        $actor = User::factory()->admin()->create();
        $application = $this->pendingApplication(['updated_by' => $actor->getKey()]);
        $before = (array) DB::table('help_applications')->where('id', $application->getKey())->first();
        $this->mock(AuditLogger::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('audit failed'));

        try {
            app(HelpApplicationReviewService::class)->start($actor, $application->reference);
            $this->fail('Expected audit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit failed', $exception->getMessage());
        }

        $after = (array) DB::table('help_applications')->where('id', $application->getKey())->first();
        $this->assertSame($before, $after);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_repeated_and_competing_requests_are_generic_no_ops_without_reassignment_or_duplicate_audit(): void
    {
        Carbon::setTestNow('2026-09-01 10:00:00');
        $winner = User::factory()->admin()->create();
        $later = User::factory()->superAdmin()->create();
        $application = $this->pendingApplication();
        $url = route('admin.help-applications.start-review', $application->reference);
        $this->actingAs($winner)->post($url)->assertSessionHas('status', 'help-application-review-started');
        $won = $application->fresh();
        Carbon::setTestNow('2026-09-01 11:00:00');

        foreach ([$winner, $later] as $actor) {
            $this->actingAs($actor)->post($url)->assertRedirect(route('admin.help-applications.index'))
                ->assertSessionHas('status', 'help-application-review-already-started')
                ->assertSessionMissing('reviewer');
        }
        $after = $application->fresh();
        $this->assertSame($won->reviewed_by, $after->reviewed_by);
        $this->assertTrue($won->review_started_at->equalTo($after->review_started_at));
        $this->assertTrue($won->status_changed_at->equalTo($after->status_changed_at));
        $this->assertSame($won->updated_by, $after->updated_by);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_deterministic_stale_selection_reports_no_new_work_after_winner_commits(): void
    {
        $winner = User::factory()->admin()->create();
        $staleActor = User::factory()->admin()->create();
        $selectedWhilePending = $this->pendingApplication();

        $this->assertSame(HelpApplicationStatus::Pending, $selectedWhilePending->status);
        $this->assertTrue(app(HelpApplicationReviewService::class)->start($winner, $selectedWhilePending->reference)->changed);
        $this->assertFalse(app(HelpApplicationReviewService::class)->start($staleActor, $selectedWhilePending->reference)->changed);
        $this->assertSame($winner->getKey(), $selectedWhilePending->fresh()->reviewed_by);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_unrelated_statuses_and_invalid_references_are_not_mutated(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $actor = User::factory()->admin()->create();
        foreach (HelpApplicationStatus::cases() as $status) {
            if (in_array($status, [HelpApplicationStatus::Pending, HelpApplicationStatus::UnderReview], true)) {
                continue;
            }
            $application = HelpApplication::factory()->create(['status' => $status]);
            $this->actingAs($actor)->post(route('admin.help-applications.start-review', $application->reference))->assertForbidden();
            $this->assertSame($status, $application->fresh()->status);
        }
        foreach (['123', 'not-a-uuid', '00000000-0000-4000-8000-000000000000'] as $reference) {
            $this->post('/admin/help-applications/'.$reference.'/start-review')->assertNotFound();
        }
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_unexpected_input_cannot_control_transition_and_private_headers_are_exact(): void
    {
        $actor = User::factory()->admin()->create();
        $other = User::factory()->admin()->create();
        $application = $this->pendingApplication();
        $response = $this->actingAs($actor)->post(route('admin.help-applications.start-review', $application->reference), [
            'reviewed_by' => $other->getKey(), 'status' => 'approved', 'category_id' => 999,
            'review_started_at' => '2000-01-01', 'updated_by' => $other->getKey(),
        ]);
        $response->assertRedirect(route('admin.help-applications.index'));
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $this->assertSame('no-cache', $response->headers->get('Pragma'));
        $fresh = $application->fresh();
        $this->assertSame($actor->getKey(), $fresh->reviewed_by);
        $this->assertSame(HelpApplicationStatus::UnderReview, $fresh->status);
        $this->assertNull($fresh->category_id);
    }

    public function test_pending_detail_has_one_exact_csrf_only_bilingual_form_and_it_disappears_after_start(): void
    {
        $actor = User::factory()->admin()->create();
        $application = $this->pendingApplication();
        $url = route('admin.help-applications.start-review', $application->reference);
        $html = $this->actingAs($actor)->get(route('admin.help-applications.show', $application->reference))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, $url));
        $this->assertStringContainsString('method="POST"', $html);
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertStringContainsString('Start review', $html);
        $this->assertStringContainsString('بدء المراجعة', $html);
        foreach (['reviewed_by', 'review_started_at', 'category_id', 'name="status"'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html);
        }
        $this->post($url);
        $this->get(route('admin.help-applications.index'))->assertOk()->assertDontSee($application->reference);
        $this->get(route('admin.help-applications.show', $application->reference))->assertNotFound();
    }

    private function pendingApplication(array $attributes = []): HelpApplication
    {
        return HelpApplication::factory()->pending()->create($attributes);
    }
}
