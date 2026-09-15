<?php

namespace Tests\Feature\Notification;

use App\Models\Campaign;
use App\Models\Category;
use App\Models\HelpApplication;
use App\Models\InternalNotification;
use App\Models\InternalNotificationEvent;
use App\Models\InternalNotificationEventRecipient;
use App\Models\User;
use App\Services\AssistanceCoordinationService;
use App\Services\InternalNotificationProjector;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class InternalNotificationTimestampCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private function migration()
    {
        return require database_path('migrations/2026_09_15_000000_correct_internal_notification_business_timestamps.php');
    }

    private function coordination(): array
    {
        $this->freezeSecond();
        $reviewer = User::factory()->admin()->create();
        $applicant = User::factory()->user()->create();
        $category = Category::factory()->create();
        $application = HelpApplication::factory()->campaignActive()->create([
            'applicant_id' => $applicant->id, 'reviewed_by' => $reviewer->id, 'category_id' => $category->id,
            'category_assigned_by' => $reviewer->id, 'category_assigned_at' => now()->subDays(2), 'decided_by' => $reviewer->id,
            'decision_note' => 'Private synthetic approval.', 'requested_amount' => '1000.50', 'status_changed_at' => now()->subHours(2),
        ]);
        Campaign::factory()->funded()->create(['help_application_id' => $application->id, 'category_id' => $category->id,
            'target_amount' => '1000.50', 'raised_amount' => '1000.50', 'created_at' => now()->subDay(),
            'published_at' => now()->subHours(2), 'funded_at' => now()->subHour()]);
        $coordination = app(AssistanceCoordinationService::class)->start($reviewer, $application->reference);

        return [$reviewer, $applicant, $application, $coordination];
    }

    private function corrupt(object $event): void
    {
        DB::table('internal_notification_events')->where('id', $event->id)->update(['occurred_at' => now()->addHours(8)]);
        DB::table('internal_notification_event_recipients')->where('event_id', $event->id)->update(['available_at' => now()->addHours(8)]);
    }

    public function test_proven_terminal_transition_and_message_repairs_preserve_every_other_field_and_are_idempotent(): void
    {
        [$reviewer, , $application, $coordination] = $this->coordination();
        app(AssistanceCoordinationService::class)->mutate($reviewer, $application->reference, $coordination->reference, true, 'message', ['revision' => '1', 'body' => 'PRIVATE-SYNTHETIC']);
        foreach (InternalNotificationEvent::all() as $event) {
            $this->corrupt($event);
        }
        $before = $this->rows();
        $preview = $this->migration()->repairHistoricalTimestamps(false);
        $this->assertSame(2, $preview['events']['repaired']);
        $this->assertSame(2, $preview['intents']['repaired']);
        $this->assertSame($before, $this->rows());
        $this->assertSame($preview, $this->migration()->repairHistoricalTimestamps());
        foreach (InternalNotificationEvent::all() as $event) {
            $this->assertSame($event->getRawOriginal('created_at'), $event->getRawOriginal('occurred_at'));
            $this->assertSame($event->getRawOriginal('occurred_at'), $event->recipientIntents->sole()->getRawOriginal('available_at'));
        }
        $after = $this->rows();
        foreach (['events' => 'occurred_at', 'intents' => 'available_at'] as $table => $field) {
            foreach ($before[$table] as $index => $row) {
                unset($row[$field], $after[$table][$index][$field]);
                $this->assertSame($row, $after[$table][$index]);
            }
        }
        $this->assertSame($before['notifications'], $after['notifications']);
        $counts = $this->migration()->repairHistoricalTimestamps();
        $this->assertSame(2, $counts['events']['already_correct']);
        $this->assertSame(2, $counts['intents']['already_correct']);
    }

    public static function ambiguities(): array
    {
        return [['event_created'], ['intent_created'], ['notification_created'], ['notification_payload'], ['deduplication_key'], ['application_link'], ['missing_notification'], ['terminal_before_business_time']];
    }

    #[DataProvider('ambiguities')]
    public function test_conflicting_or_unproven_evidence_is_deliberately_preserved(string $conflict): void
    {
        $this->coordination();
        $event = InternalNotificationEvent::sole();
        $this->corrupt($event);
        match ($conflict) {
            'event_created' => DB::table('internal_notification_events')->update(['created_at' => now()->subSecond()]),
            'intent_created' => DB::table('internal_notification_event_recipients')->update(['created_at' => now()->subSecond()]),
            'notification_created' => DB::table('internal_notifications')->update(['created_at' => now()->addSecond()]),
            'notification_payload' => DB::table('internal_notifications')->update(['data' => json_encode(['coordination_reference' => 'unproven', 'state' => 'awaiting_applicant'])]),
            'deduplication_key' => DB::table('internal_notification_events')->update(['deduplication_key' => str_repeat('a', 64)]),
            'missing_notification' => DB::table('internal_notifications')->delete(),
            'terminal_before_business_time' => DB::table('internal_notification_events')->update(['projected_at' => now()->subSecond()]),
            'application_link' => DB::table('internal_notification_events')->update(['help_application_id' => HelpApplication::factory()->create()->id]),
        };
        $before = $this->rows();
        $counts = $this->migration()->repairHistoricalTimestamps();
        $this->assertSame(1, $counts['events']['preserved_ambiguous']);
        $this->assertSame(1, $counts['intents']['preserved_ambiguous']);
        $this->assertSame($before, $this->rows());
    }

    public function test_retried_terminal_schedule_is_preserved_even_when_event_business_time_is_provable(): void
    {
        $this->coordination();
        $event = InternalNotificationEvent::sole();
        $this->corrupt($event);
        DB::table('internal_notification_event_recipients')->update(['attempts' => 2]);
        $schedule = InternalNotificationEventRecipient::sole()->getRawOriginal('available_at');
        $counts = $this->migration()->repairHistoricalTimestamps();
        $this->assertSame(1, $counts['events']['repaired']);
        $this->assertSame(1, $counts['intents']['preserved_retry']);
        $this->assertSame($schedule, InternalNotificationEventRecipient::sole()->getRawOriginal('available_at'));
    }

    public function test_pending_schedules_and_older_producers_never_receive_invented_creation_time_equality(): void
    {
        $intent = InternalNotificationEventRecipient::factory()->create(['available_at' => now()->addHour()]);
        $before = $this->rows();
        $counts = $this->migration()->repairHistoricalTimestamps();
        $this->assertSame(1, $counts['events']['preserved_unfinished']);
        $this->assertSame(1, $counts['intents']['preserved_unfinished']);
        $this->assertSame($before, $this->rows());
        $intent->forceFill(['state' => 'cancelled', 'attempts' => 1, 'projected_at' => now()])->save();
        $intent->event->forceFill(['projected_at' => now()])->save();
        $before = $this->rows();
        $counts = $this->migration()->repairHistoricalTimestamps();
        $this->assertSame(1, $counts['events']['preserved_unrelated']);
        $this->assertSame(1, $counts['intents']['preserved_unrelated']);
        $this->assertSame($before, $this->rows());
    }

    public static function outcomes(): array
    {
        return [['projected'], ['cancelled'], ['retry']];
    }

    #[DataProvider('outcomes')]
    public function test_projection_only_explicit_retry_scheduling_changes_availability_and_never_event_time(string $outcome): void
    {
        $this->freezeSecond();
        $intent = InternalNotificationEventRecipient::factory()->create();
        $eventTime = $intent->event->getRawOriginal('occurred_at');
        $availability = $intent->getRawOriginal('available_at');
        $this->travel(5)->minutes();
        if ($outcome === 'cancelled') {
            $intent->recipient->delete();
        } elseif ($outcome === 'retry') {
            Log::spy();
            InternalNotification::creating(fn () => throw new RuntimeException('Synthetic failure.'));
        }
        try {
            app(InternalNotificationProjector::class)->projectEvent($intent->event_id);
        } finally {
            InternalNotification::flushEventListeners();
        }
        $this->assertSame($eventTime, $intent->event->fresh()->getRawOriginal('occurred_at'));
        $this->assertSame($outcome === 'retry' ? now()->addSeconds(60)->format('Y-m-d H:i:s') : $availability, $intent->fresh()->getRawOriginal('available_at'));
        if ($outcome === 'retry') {
            $scheduled = $intent->fresh()->getRawOriginal('available_at');
            $this->travel(60)->seconds();
            app(InternalNotificationProjector::class)->projectReady();
            $this->assertSame($scheduled, $intent->fresh()->getRawOriginal('available_at'));
            $this->assertSame($eventTime, $intent->event->fresh()->getRawOriginal('occurred_at'));
        }
    }

    public static function drivers(): array
    {
        return [[MySqlConnection::class, 'mysql', '8.0.36'], [MariaDbConnection::class, 'mariadb', '10.6.23'], [MariaDbConnection::class, 'mariadb', '10.11.8']];
    }

    #[DataProvider('drivers')]
    public function test_forward_migration_compiles_in_place_datetime_without_generated_defaults_or_updates(string $class, string $driver, string $version): void
    {
        $connection = Mockery::mock($class.'[getServerVersion]', [fn () => throw new RuntimeException('No database connection permitted.'), 'schema_only', '', ['driver' => $driver]]);
        $connection->shouldReceive('getServerVersion')->andReturn($version);
        $connection->useDefaultSchemaGrammar();
        $original = Schema::getFacadeRoot();
        $sql = [];
        Schema::shouldReceive('table')->twice()->andReturnUsing(function ($table, $callback) use ($connection, &$sql): void {
            $blueprint = new Blueprint($connection, $table, $callback);
            $sql[$table] = implode("\n", $blueprint->toSql());
        });
        try {
            $this->migration()->up();
        } finally {
            Schema::swap($original);
        }
        foreach (['internal_notification_events' => 'occurred_at', 'internal_notification_event_recipients' => 'available_at'] as $table => $field) {
            $this->assertStringContainsString('alter table `'.$table.'` modify `'.$field.'` datetime not null', $sql[$table]);
            foreach (['current_timestamp', 'on update', 'default', 'drop', 'create table'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, strtolower($sql[$table]));
            }
        }
    }

    public function test_schema_and_forward_only_down_preserve_rows_foreign_keys_and_indexes(): void
    {
        $this->coordination();
        $before = $this->rows();
        foreach (['internal_notification_events' => 'occurred_at', 'internal_notification_event_recipients' => 'available_at'] as $table => $field) {
            $column = collect(Schema::getColumns($table))->firstWhere('name', $field);
            $this->assertSame('datetime', $column['type_name']);
            $this->assertFalse($column['nullable']);
            $this->assertNull($column['default']);
            $this->assertNotEmpty(Schema::getForeignKeys($table));
            $this->assertNotEmpty(Schema::getIndexes($table));
        }
        try {
            $this->migration()->down();
            $this->fail('Unsafe rollback accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('forward-only', $exception->getMessage());
        }
        $this->assertSame($before, $this->rows());
    }

    private function rows(): array
    {
        return ['events' => DB::table('internal_notification_events')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'intents' => DB::table('internal_notification_event_recipients')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'notifications' => DB::table('internal_notifications')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()];
    }
}
