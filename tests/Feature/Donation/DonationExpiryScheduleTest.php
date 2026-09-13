<?php

namespace Tests\Feature\Donation;

use App\Enums\DonationStatus;
use App\Enums\HelpApplicationStatus;
use App\Models\Campaign;
use App\Models\Donation;
use App\Models\HelpApplication;
use App\Services\DonationService;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\TestCase;

class DonationExpiryScheduleTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = ['donations', 'payment_attempts', 'campaigns', 'help_applications', 'audit_logs', 'internal_notification_events', 'internal_notification_event_recipients', 'internal_notifications'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSecond();
    }

    private function expiryEvent(): ScheduledEvent
    {
        app(Kernel::class)->bootstrap();
        $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains($event->command ?? '', 'donations:expire'));
        $this->assertCount(1, $events);

        return $events->first();
    }

    private function snapshot(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), self::TABLES);
    }

    private function pending(Campaign $campaign): Donation
    {
        $token = $this->get('/en/cases/'.$campaign->slug.'/donate')->assertOk()->viewData('idempotencyToken');
        $this->post('/en/cases/'.$campaign->slug.'/donate', ['amount' => '10', 'idempotency_token' => $token])->assertRedirect();

        return Donation::query()->latest('id')->firstOrFail();
    }

    private function campaign(): Campaign
    {
        $application = HelpApplication::factory()->create(['status' => HelpApplicationStatus::CampaignActive]);

        return Campaign::factory()->active()->create(['help_application_id' => $application->id, 'target_amount' => '100.00', 'raised_amount' => '25.00', 'expires_at' => now()->addDay()]);
    }

    public function test_schedule_has_exactly_one_minutely_bounded_non_overlapping_sandbox_task(): void
    {
        config(['donations.driver' => 'disabled']);
        $this->app->beforeResolving(DonationService::class, fn () => $this->fail('Schedule registration resolved the donation service.'));
        $event = $this->expiryEvent();
        $this->assertStringEndsWith('donations:expire --limit=100', $event->command);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertNull($event->repeatSeconds);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertFalse($event->runInBackground);
        $this->assertFalse($event->filtersPass($this->app));
        config(['donations.driver' => 'sandbox']);
        $this->assertTrue($event->filtersPass($this->app));
    }

    public function test_scheduler_expires_due_pairs_preserves_future_checkouts_and_funds_and_is_idempotent(): void
    {
        config(['donations.driver' => 'sandbox']);
        $campaign = $this->campaign();
        $due = $this->pending($campaign);
        $this->travel(15)->minutes();
        $future = $this->pending($campaign);
        $this->travel(16)->minutes();
        $campaignBefore = $campaign->fresh()->getRawOriginal();
        $applicationBefore = $campaign->helpApplication->getRawOriginal();
        $source = $this->expiryEvent();
        // Keep real scheduler filters, mutex lifecycle, and command handling; replace only the
        // subprocess transport so expiry uses this test's isolated in-memory database.
        $event = Mockery::mock(ScheduledEvent::class.'[execute]', [$source->mutex, $source->command, $source->timezone])->shouldAllowMockingProtectedMethods();
        foreach (get_object_vars($source) as $name => $value) {
            $event->$name = $value;
        }
        foreach (['filters', 'rejects'] as $name) {
            $property = new ReflectionProperty(ScheduledEvent::class, $name);
            $property->setValue($event, $property->getValue($source));
        }
        $event->shouldReceive('execute')->twice()->andReturnUsing(fn () => Artisan::call('donations:expire', ['--limit' => 100]));
        (new ReflectionProperty(Schedule::class, 'events'))->setValue(app(Schedule::class), [$event]);
        Event::fake([ScheduledTaskFailed::class, ScheduledTaskFinished::class]);
        $this->artisan('schedule:run', ['--whisper' => true])->assertSuccessful();
        $this->assertSame(DonationStatus::Expired, $due->fresh()->status);
        $this->assertSame(DonationStatus::Expired, $due->paymentAttempt->fresh()->status);
        $this->assertEquals($due->fresh()->completed_at, $due->paymentAttempt->fresh()->completed_at);
        $this->assertSame(DonationStatus::Pending, $future->fresh()->status);
        $this->assertSame(DonationStatus::Pending, $future->paymentAttempt->fresh()->status);
        $this->assertSame($campaignBefore, $campaign->fresh()->getRawOriginal());
        $this->assertSame($applicationBefore, $campaign->helpApplication->fresh()->getRawOriginal());
        $this->assertNull($due->fresh()->paid_at);
        $this->assertDatabaseCount('audit_logs', 2);
        $this->assertDatabaseCount('internal_notification_events', 0);
        $this->assertDatabaseCount('internal_notification_event_recipients', 0);
        $this->assertDatabaseCount('internal_notifications', 0);
        $after = $this->snapshot();
        $this->artisan('schedule:run', ['--whisper' => true])->assertSuccessful();
        $this->assertSame($after, $this->snapshot());
        Event::assertNotDispatched(ScheduledTaskFailed::class);
        Event::assertDispatchedTimes(ScheduledTaskFinished::class, 2);
        $this->assertFalse($source->mutex->exists($source));
    }

    #[DataProvider('blockedDrivers')]
    public function test_disabled_or_unsupported_schedule_skips_without_resolution_mutations_failure_or_logs(mixed $driver): void
    {
        config(['donations.driver' => 'sandbox']);
        $this->pending($this->campaign());
        $this->travel(31)->minutes();
        $before = $this->snapshot();
        config(['donations.driver' => $driver]);
        $this->app->beforeResolving(DonationService::class, fn () => $this->fail('Skipped expiry resolved the donation service.'));
        $this->expiryEvent();
        Log::spy();
        Event::fake([ScheduledTaskStarting::class, ScheduledTaskFinished::class, ScheduledTaskFailed::class, ScheduledTaskSkipped::class]);
        $this->artisan('schedule:run', ['--whisper' => true])->doesntExpectOutput()->assertSuccessful();
        $this->assertSame($before, $this->snapshot());
        Event::assertNotDispatched(ScheduledTaskStarting::class);
        Event::assertNotDispatched(ScheduledTaskFinished::class);
        Event::assertNotDispatched(ScheduledTaskFailed::class);
        Event::assertDispatchedTimes(ScheduledTaskSkipped::class, 1);
        foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency', 'log'] as $level) {
            Log::shouldNotHaveReceived($level);
        }
    }

    public static function blockedDrivers(): array
    {
        return [['disabled'], [null], ['unsupported'], ['Sandbox'], [' sandbox'], [false], [0], [['sandbox']]];
    }

    public function test_existing_mutex_prevents_overlapping_scheduler_execution(): void
    {
        config(['donations.driver' => 'sandbox']);
        $event = $this->expiryEvent();
        $this->assertTrue($event->mutex->create($event));
        $this->app->beforeResolving(DonationService::class, fn () => $this->fail('Overlapping expiry resolved the donation service.'));
        Event::fake([ScheduledTaskStarting::class, ScheduledTaskFailed::class, ScheduledTaskSkipped::class]);
        try {
            $this->artisan('schedule:run', ['--whisper' => true])->doesntExpectOutput()->assertSuccessful();
            Event::assertNotDispatched(ScheduledTaskStarting::class);
            Event::assertNotDispatched(ScheduledTaskFailed::class);
            Event::assertDispatchedTimes(ScheduledTaskSkipped::class, 1);
        } finally {
            $event->mutex->forget($event);
        }
    }
}
