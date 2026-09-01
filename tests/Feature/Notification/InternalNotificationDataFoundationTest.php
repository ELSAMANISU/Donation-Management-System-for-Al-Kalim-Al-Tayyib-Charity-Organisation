<?php

namespace Tests\Feature\Notification;

use App\Enums\InternalNotificationAudience;
use App\Enums\InternalNotificationEventType;
use App\Enums\InternalNotificationProjectionState;
use App\Enums\InternalNotificationType;
use App\Models\InternalNotification;
use App\Models\InternalNotificationEvent;
use App\Models\InternalNotificationEventRecipient;
use App\Models\User;
use App\Services\InternalNotificationEventKey;
use App\Services\InternalNotificationPayload;
use App\Services\InternalNotificationProjector;
use App\Services\InternalNotificationRecipientSelector;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class InternalNotificationDataFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_and_enums_are_exact(): void
    {
        $this->assertSame(['help_application_submitted'], array_column(InternalNotificationEventType::cases(), 'value'));
        $this->assertSame(['applicant', 'administrator'], array_column(InternalNotificationAudience::cases(), 'value'));
        $this->assertSame(['help_application_submission_confirmation', 'help_application_new_submission'], array_column(InternalNotificationType::cases(), 'value'));
        $this->assertSame(['pending', 'projected', 'cancelled'], array_column(InternalNotificationProjectionState::cases(), 'value'));

        $this->assertTrue(Schema::hasColumns('internal_notification_events', ['id', 'reference', 'type', 'help_application_id', 'deduplication_key', 'occurred_at', 'projected_at', 'created_at']));
        $this->assertTrue(Schema::hasColumns('internal_notification_event_recipients', ['id', 'event_id', 'recipient_id', 'recipient_role', 'audience', 'notification_type', 'state', 'attempts', 'available_at', 'last_attempted_at', 'projected_at', 'created_at']));
        $this->assertTrue(Schema::hasColumns('internal_notifications', ['id', 'reference', 'event_recipient_id', 'recipient_id', 'type', 'data', 'read_at', 'created_at']));

        $indexes = collect(DB::select("PRAGMA index_list('internal_notifications')"))->pluck('name');
        $this->assertContains('internal_notifications_recipient_read_index', $indexes);
        $this->assertTrue(collect(DB::select("PRAGMA foreign_key_list('internal_notifications')"))->contains(fn ($fk) => $fk->table === 'users' && strtolower($fk->on_delete) === 'set null'));
    }

    public function test_payload_and_event_key_are_exact_and_private(): void
    {
        $reference = '123e4567-e89b-12d3-a456-426614174000';
        $payload = app(InternalNotificationPayload::class);
        $this->assertSame(['application_reference' => $reference, 'status' => 'pending'], $payload->build(InternalNotificationType::HelpApplicationSubmissionConfirmation, $reference));

        foreach ([[], ['application_reference' => $reference], ['application_reference' => $reference, 'status' => 'pending', 'extra' => true], ['application_reference' => 'bad', 'status' => 'pending']] as $unsafe) {
            try {
                $payload->validate(InternalNotificationType::HelpApplicationNewSubmission, $unsafe);
                $this->fail('Unsafe payload was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('Internal notification payload is invalid.', $exception->getMessage());
            }
        }

        $key = app(InternalNotificationEventKey::class)->make(InternalNotificationEventType::HelpApplicationSubmitted, 42);
        $this->assertSame(hash('sha256', implode("\0", ['internal_notification', 'help_application_submitted', '42'])), $key);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $key);
    }

    public function test_factories_relationships_scopes_and_serialization_are_private(): void
    {
        $notification = InternalNotification::factory()->create();
        $this->assertSame('reference', $notification->getRouteKeyName());
        $this->assertTrue($notification->recipient->is($notification->eventRecipient->recipient));
        $this->assertNotNull($notification->eventRecipient->event->application);
        $this->assertArrayNotHasKey('data', $notification->toArray());
        $this->assertArrayNotHasKey('recipient_id', $notification->toArray());
        $this->assertSame(['application_reference', 'status'], array_keys($notification->allowlistedData()));
        $this->assertSame(1, InternalNotification::query()->forRecipient($notification->recipient)->unread()->count());

        $notification->read_at = now();
        $notification->save();
        $this->assertSame(1, InternalNotification::query()->forRecipient($notification->recipient_id)->read()->newestFirst()->count());
    }

    public function test_database_deduplication_constraints_are_enforced(): void
    {
        $event = InternalNotificationEvent::factory()->create();
        $this->expectException(QueryException::class);
        InternalNotificationEvent::factory()->create([
            'help_application_id' => $event->help_application_id,
            'deduplication_key' => $event->deduplication_key,
        ]);
    }

    public function test_recipient_selector_returns_models_from_the_test_transaction(): void
    {
        User::factory()->admin()->create();
        $this->assertContainsOnlyInstancesOf(User::class, app(InternalNotificationRecipientSelector::class)->lockedEligibleAdministrators());
    }

    public function test_recipient_selector_eligibility_inside_transaction(): void
    {
        User::factory()->user()->create();
        $later = User::factory()->superAdmin()->create();
        $earlier = User::factory()->admin()->create();
        User::factory()->admin()->disabled()->create();
        User::factory()->superAdmin()->mustChangePassword()->create();
        User::factory()->admin()->unverified()->create();

        $ids = DB::transaction(fn () => array_column(app(InternalNotificationRecipientSelector::class)->lockedEligibleAdministrators(), 'id'));
        $this->assertSame(collect([$later->id, $earlier->id, User::query()->whereNull('email_verified_at')->value('id')])->sort()->values()->all(), $ids);
    }

    public function test_projector_projects_once_and_finishes_event(): void
    {
        Notification::fake();
        $intent = InternalNotificationEventRecipient::factory()->create();
        $result = app(InternalNotificationProjector::class)->projectEvent($intent->event_id);
        $this->assertSame(1, $result->projected);
        $this->assertSame(1, InternalNotification::query()->count());
        $this->assertSame(InternalNotificationProjectionState::Projected, $intent->refresh()->state);
        $this->assertNotNull($intent->event->refresh()->projected_at);

        $retry = app(InternalNotificationProjector::class)->projectEvent($intent->event_id);
        $this->assertSame(0, $retry->projected);
        $this->assertSame(0, $retry->cancelled);
        $this->assertSame(0, $retry->remaining);
        $this->assertSame(1, InternalNotification::query()->count());
        Notification::assertNothingSent();
    }

    public function test_project_event_remaining_is_event_scoped_while_project_ready_is_global(): void
    {
        $event = InternalNotificationEvent::factory()->create();
        InternalNotificationEventRecipient::factory()->count(2)->create(['event_id' => $event->id]);
        InternalNotificationEventRecipient::factory()->create();

        $eventResult = app(InternalNotificationProjector::class)->projectEvent($event, 1);
        $this->assertSame(1, $eventResult->projected);
        $this->assertSame(1, $eventResult->remaining);

        $globalResult = app(InternalNotificationProjector::class)->projectReady(1);
        $this->assertSame(1, $globalResult->projected);
        $this->assertSame(1, $globalResult->remaining);
    }

    public function test_stale_selected_terminal_intent_reports_no_new_work(): void
    {
        $intent = InternalNotificationEventRecipient::factory()->create();
        $event = $intent->event;
        $originalAttempts = $intent->attempts;
        $originalAvailableAt = $intent->available_at;
        $terminalAt = now()->addSecond();
        $changed = false;
        Log::spy();

        DB::listen(function ($query) use ($event, $intent, $terminalAt, &$changed): void {
            if (! $changed
                && str_starts_with(strtolower(ltrim($query->sql)), 'select')
                && str_contains($query->sql, 'internal_notification_event_recipients')) {
                $changed = true;
                DB::table('internal_notification_event_recipients')->where('id', $intent->id)->update([
                    'state' => InternalNotificationProjectionState::Projected->value,
                    'projected_at' => $terminalAt,
                ]);
                DB::table('internal_notification_events')->where('id', $event->id)->update(['projected_at' => $terminalAt]);
            }
        });

        $result = app(InternalNotificationProjector::class)->projectEvent($intent->event_id);
        $this->assertTrue($changed);
        $this->assertSame(0, $result->projected);
        $this->assertSame(0, $result->cancelled);
        $this->assertSame(0, $result->failed);
        $this->assertDatabaseCount('internal_notifications', 0);
        $intent->refresh();
        $this->assertSame(InternalNotificationProjectionState::Projected, $intent->state);
        $this->assertSame($originalAttempts, $intent->attempts);
        $this->assertNull($intent->last_attempted_at);
        $this->assertTrue($intent->available_at->equalTo($originalAvailableAt));
        $this->assertSame($terminalAt->format('Y-m-d H:i:s'), $intent->projected_at->format('Y-m-d H:i:s'));
        $this->assertSame($terminalAt->format('Y-m-d H:i:s'), $event->refresh()->projected_at->format('Y-m-d H:i:s'));
        Log::shouldNotHaveReceived('warning');
    }

    public function test_invalid_or_missing_event_is_rejected_without_unrelated_counts(): void
    {
        InternalNotificationEventRecipient::factory()->create();

        foreach ([0, PHP_INT_MAX] as $eventId) {
            try {
                app(InternalNotificationProjector::class)->projectEvent($eventId);
                $this->fail('Invalid event was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('Internal notification event is invalid.', $exception->getMessage());
            }
        }

        $this->assertDatabaseCount('internal_notifications', 0);
    }

    public function test_projection_failure_is_delayed_once_and_later_retry_projects_exactly_once(): void
    {
        Carbon::setTestNow('2026-09-01 12:00:00');

        try {
            $intent = InternalNotificationEventRecipient::factory()->create();
            DB::table('help_applications')->where('id', $intent->event->help_application_id)->update(['reference' => 'malformed-private-reference']);
            Log::shouldReceive('warning')->once()->with('Internal notification projection failed.');

            $failed = app(InternalNotificationProjector::class)->projectEvent($intent->event_id);
            $intent->refresh();
            $this->assertSame(1, $failed->failed);
            $this->assertSame(1, $failed->remaining);
            $this->assertSame(InternalNotificationProjectionState::Pending, $intent->state);
            $this->assertSame(1, $intent->attempts);
            $this->assertTrue($intent->last_attempted_at->equalTo(now()));
            $this->assertTrue($intent->available_at->equalTo(now()->addSeconds(60)));
            $this->assertNull($intent->event->refresh()->projected_at);
            $this->assertDatabaseCount('internal_notifications', 0);

            DB::table('help_applications')->where('id', $intent->event->help_application_id)->update(['reference' => (string) Str::uuid()]);
            Carbon::setTestNow(now()->addSeconds(60));
            $retry = app(InternalNotificationProjector::class)->projectEvent($intent->event_id);
            $this->assertSame(1, $retry->projected);
            $this->assertSame(0, $retry->failed);
            $this->assertDatabaseCount('internal_notifications', 1);
            $this->assertSame(2, $intent->refresh()->attempts);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_logging_failure_does_not_change_recoverable_failure_state_or_expose_context(): void
    {
        $intent = InternalNotificationEventRecipient::factory()->create();
        DB::table('help_applications')->where('id', $intent->event->help_application_id)->update(['reference' => 'private-malformed-reference']);
        Log::shouldReceive('warning')->once()->with('Internal notification projection failed.')->andThrow(new RuntimeException('logging failed'));

        $result = app(InternalNotificationProjector::class)->projectEvent($intent->event_id);
        $this->assertSame(1, $result->failed);
        $this->assertSame(InternalNotificationProjectionState::Pending, $intent->refresh()->state);
        $this->assertSame(1, $intent->attempts);
        $this->assertDatabaseCount('internal_notifications', 0);
    }

    public function test_failure_recording_failure_preserves_original_pending_state(): void
    {
        $intent = InternalNotificationEventRecipient::factory()->create();
        $originalAvailableAt = $intent->available_at;
        DB::table('help_applications')->where('id', $intent->event->help_application_id)->update(['reference' => 'private-malformed-reference']);
        DB::statement("CREATE TRIGGER reject_projection_failure_record BEFORE UPDATE OF attempts ON internal_notification_event_recipients WHEN NEW.id = {$intent->id} AND NEW.attempts > 0 BEGIN SELECT RAISE(ABORT, 'failure recording unavailable'); END");

        try {
            Log::shouldReceive('warning')->once()->with('Internal notification projection failed.');
            $result = app(InternalNotificationProjector::class)->projectEvent($intent->event_id);
            $this->assertSame(1, $result->failed);
            $intent->refresh();
            $this->assertSame(InternalNotificationProjectionState::Pending, $intent->state);
            $this->assertSame(0, $intent->attempts);
            $this->assertNull($intent->last_attempted_at);
            $this->assertTrue($intent->available_at->equalTo($originalAvailableAt));
            $this->assertDatabaseCount('internal_notifications', 0);
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS reject_projection_failure_record');
        }
    }

    public function test_invalid_projection_configuration_fails_before_any_mutation(): void
    {
        $intent = InternalNotificationEventRecipient::factory()->create();
        $original = config('internal_notifications.projection');
        $cases = [
            [['default_batch_size' => 0], null],
            [['default_batch_size' => '10'], null],
            [['maximum_batch_size' => 0], 1],
            [['maximum_batch_size' => 1001], 1],
            [['maximum_batch_size' => '500'], 1],
            [['retry_delay_seconds' => 0], 1],
            [['retry_delay_seconds' => 86401], 1],
            [['retry_delay_seconds' => '60'], 1],
            [[], 0],
            [[], 501],
        ];

        try {
            foreach ($cases as [$changes, $limit]) {
                config()->set('internal_notifications.projection', array_merge($original, $changes));
                try {
                    app(InternalNotificationProjector::class)->projectEvent($intent->event_id, $limit);
                    $this->fail('Unsafe projection configuration was accepted.');
                } catch (InvalidArgumentException) {
                    $intent->refresh();
                    $this->assertSame(0, $intent->attempts);
                    $this->assertNull($intent->last_attempted_at);
                    $this->assertSame(InternalNotificationProjectionState::Pending, $intent->state);
                    $this->assertDatabaseCount('internal_notifications', 0);
                }
            }
        } finally {
            config()->set('internal_notifications.projection', $original);
        }
    }

    public function test_projector_cancels_deleted_recipient_and_retains_history(): void
    {
        $intent = InternalNotificationEventRecipient::factory()->create();
        $recipient = $intent->recipient;
        $recipient->delete();
        $this->assertNull($intent->refresh()->recipient_id);
        $result = app(InternalNotificationProjector::class)->projectReady();
        $this->assertSame(1, $result->cancelled);
        $this->assertDatabaseCount('internal_notifications', 0);
        $this->assertDatabaseHas('internal_notification_event_recipients', ['id' => $intent->id, 'state' => 'cancelled']);

        $retry = app(InternalNotificationProjector::class)->projectEvent($intent->event_id);
        $this->assertSame(0, $retry->projected);
        $this->assertSame(0, $retry->cancelled);
        $this->assertDatabaseCount('internal_notifications', 0);
    }

    public function test_event_finishes_only_after_every_intent_is_terminal(): void
    {
        $event = InternalNotificationEvent::factory()->create();
        InternalNotificationEventRecipient::factory()->count(2)->create(['event_id' => $event->id]);
        app(InternalNotificationProjector::class)->projectEvent($event, 1);
        $this->assertNull($event->refresh()->projected_at);
        app(InternalNotificationProjector::class)->projectEvent($event, 1);
        $this->assertNotNull($event->refresh()->projected_at);
    }

    public function test_command_reports_counts_only_and_rejects_invalid_limit(): void
    {
        InternalNotificationEventRecipient::factory()->create();
        $this->artisan('internal-notifications:project-pending', ['--limit' => 1])
            ->expectsOutput('projected: 1')->expectsOutput('cancelled: 0')->expectsOutput('failed: 0')->expectsOutput('remaining: 0')->assertSuccessful();
        $this->artisan('internal-notifications:project-pending', ['--limit' => 0])
            ->expectsOutput('Internal notification projection could not run.')->assertFailed();
    }

    public function test_command_returns_failure_with_counts_when_projection_fails(): void
    {
        $intent = InternalNotificationEventRecipient::factory()->create();
        DB::table('help_applications')->where('id', $intent->event->help_application_id)->update(['reference' => 'private-malformed-reference']);

        $this->artisan('internal-notifications:project-pending', ['--limit' => 1])
            ->expectsOutput('projected: 0')
            ->expectsOutput('cancelled: 0')
            ->expectsOutput('failed: 1')
            ->expectsOutput('remaining: 1')
            ->doesntExpectOutputToContain('private-malformed-reference')
            ->assertFailed();
    }
}
