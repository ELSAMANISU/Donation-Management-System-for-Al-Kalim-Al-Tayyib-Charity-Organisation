<?php

namespace Tests\Feature\Admin;

use App\Enums\HelpApplicationStatus;
use App\Enums\InternalNotificationEventType;
use App\Enums\InternalNotificationType;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\HelpApplication;
use App\Models\HelpApplicationDocument;
use App\Models\HelpApplicationDuplicateWarning as Warning;
use App\Models\InternalNotification;
use App\Models\InternalNotificationEvent;
use App\Models\InternalNotificationEventRecipient;
use App\Models\User;
use App\Services\HelpApplicationDecisionService;
use App\Services\InternalNotificationEventKey;
use App\Services\InternalNotificationPayload;
use App\Services\InternalNotificationProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class HelpApplicationDecisionTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->freezeSecond();
        $actor = User::factory()->admin()->create();
        $application = HelpApplication::factory()->underReview()->assignedTo(Category::factory()->create(), $actor)
            ->create(['reviewed_by' => $actor->id]);

        return [$actor, $application];
    }

    private function url(HelpApplication $application): string
    {
        return route('admin.help-applications.in-review.decide', $application->reference);
    }

    private function show(HelpApplication $application): string
    {
        return route('admin.help-applications.in-review.show', $application->reference);
    }

    private function payload(string $outcome = 'approved'): array
    {
        return ['outcome' => $outcome, 'decision_note' => "  Private  deliberate\n decision note.  "];
    }

    private function assertNoEffects(): void
    {
        foreach (['audit_logs', 'internal_notification_events', 'internal_notification_event_recipients', 'internal_notifications'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_route_is_exact_uuid_constrained_post_with_exact_middleware_and_order(): void
    {
        $routes = collect(app('router')->getRoutes());
        $decisions = $routes->filter(fn ($route) => str_ends_with($route->uri(), '/decide'))->values();
        $this->assertCount(1, $decisions);
        $route = $decisions->first();
        $this->assertSame('admin.help-applications.in-review.decide', $route->getName());
        $this->assertSame('admin/help-applications/in-review/{helpApplication}/decide', $route->uri());
        $this->assertSame(['POST'], $route->methods());
        $this->assertSame(['web', 'auth', 'role:admin,super_admin', 'throttle:10,1'], $route->gatherMiddleware());
        $this->assertSame(['helpApplication' => '[\\da-fA-F]{8}-[\\da-fA-F]{4}-[\\da-fA-F]{4}-[\\da-fA-F]{4}-[\\da-fA-F]{12}'], $route->wheres);
        $this->assertLessThan($routes->search(fn ($r) => $r->getName() === 'admin.help-applications.show'), $routes->search(fn ($r) => $r === $route));
    }

    public function test_rate_limit_rejects_the_eleventh_attempt(): void
    {
        [$actor, $application] = $this->fixture();
        $this->actingAs($actor);
        for ($i = 0; $i < 10; $i++) {
            $this->post($this->url($application), [])->assertRedirect($this->show($application));
        }
        $this->post($this->url($application), [])->assertStatus(429);
        $this->assertNoEffects();
    }

    public function test_guest_applicant_disabled_and_password_change_accounts_are_concealed_before_validation(): void
    {
        [$actor, $application] = $this->fixture();
        $this->post($this->url($application), [])->assertNotFound();
        foreach ([User::factory()->user()->create(), User::factory()->admin()->disabled()->create(), User::factory()->admin()->mustChangePassword()->create()] as $ineligible) {
            $this->actingAs($ineligible)->post($this->url($application), [])->assertNotFound()->assertSessionMissing('_old_input');
        }
        $this->assertNoEffects();
    }

    public function test_foreign_and_orphaned_assignments_are_concealed_for_normal_admin(): void
    {
        [$actor, $application] = $this->fixture();
        foreach ([User::factory()->admin()->create()->id, null] as $reviewer) {
            DB::table('help_applications')->where('id', $application->id)->update(['reviewed_by' => $reviewer]);
            $this->actingAs($actor)->post($this->url($application), [])->assertNotFound();
        }
        $this->assertNoEffects();
    }

    public static function oversight(): array
    {
        return ['assigned' => [false], 'orphaned' => [true]];
    }

    #[DataProvider('oversight')]
    public function test_super_admin_can_decide_assigned_and_orphaned_applications(bool $orphaned): void
    {
        [$actor, $application] = $this->fixture();
        if ($orphaned) {
            DB::table('help_applications')->where('id', $application->id)->update(['reviewed_by' => null]);
        }
        $super = User::factory()->superAdmin()->create();
        $this->actingAs($super)->post($this->url($application), $this->payload())->assertRedirect(route('admin.help-applications.in-review.index'));
        $this->assertSame($super->id, $application->refresh()->decided_by);
    }

    public static function otherStatuses(): array
    {
        $cases = [];
        foreach (HelpApplicationStatus::cases() as $status) {
            if ($status !== HelpApplicationStatus::UnderReview) {
                $cases[$status->value] = [$status->value];
            }
        }

        return $cases;
    }

    #[DataProvider('otherStatuses')]
    public function test_every_other_status_is_concealed_even_with_invalid_input(string $status): void
    {
        [$actor, $application] = $this->fixture();
        DB::table('help_applications')->where('id', $application->id)->update(['status' => $status]);
        $this->actingAs($actor)->post($this->url($application), [])->assertNotFound();
        $this->assertNoEffects();
    }

    public function test_malformed_and_nonexistent_references_are_concealed(): void
    {
        [$actor] = $this->fixture();
        foreach (['bad', (string) Str::uuid()] as $reference) {
            $this->actingAs($actor)->post(route('admin.help-applications.in-review.decide', $reference), [])->assertNotFound();
        }
        $this->assertNoEffects();
    }

    public static function invalidInputs(): array
    {
        return [
            'missing outcome' => [null, 'long enough note', 'outcome', 'Select a supported outcome. / اختر نتيجة مدعومة.'],
            'array outcome' => [[], 'long enough note', 'outcome', 'Select a supported outcome. / اختر نتيجة مدعومة.'],
            'numeric outcome' => [1, 'long enough note', 'outcome', 'Select a supported outcome. / اختر نتيجة مدعومة.'],
            'padded outcome' => [' approved ', 'long enough note', 'outcome', 'Select a supported outcome. / اختر نتيجة مدعومة.'],
            'uppercase outcome' => ['Approved', 'long enough note', 'outcome', 'Select a supported outcome. / اختر نتيجة مدعومة.'],
            'unsupported outcome' => ['pending', 'long enough note', 'outcome', 'Select a supported outcome. / اختر نتيجة مدعومة.'],
            'missing note' => ['approved', null, 'decision_note', 'Enter a decision note. / أدخل ملاحظة القرار.'],
            'array note' => ['approved', ['private'], 'decision_note', 'The decision note must be text. / يجب أن تكون ملاحظة القرار نصًا.'],
            'numeric note' => ['rejected', 1234567890, 'decision_note', 'The decision note must be text. / يجب أن تكون ملاحظة القرار نصًا.'],
            'short trimmed note' => ['approved', ' 123456789 ', 'decision_note', 'The decision note must contain at least 10 characters. / يجب أن تتكون ملاحظة القرار من 10 أحرف على الأقل.'],
            'too long note' => ['rejected', str_repeat('ن', 2001), 'decision_note', 'The decision note must not exceed 2000 characters. / يجب ألا تتجاوز ملاحظة القرار 2000 حرف.'],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_validation_has_exact_private_bilingual_errors_and_only_canonical_outcome_is_flashed(mixed $outcome, mixed $note, string $field, string $message): void
    {
        [$actor, $application] = $this->fixture();
        $response = $this->actingAs($actor)->post($this->url($application), [
            'outcome' => $outcome, 'decision_note' => $note, 'password' => 'sentinel-secret', 'unexpected' => 'sentinel-secret',
            'category' => 'sentinel-secret', 'identity_document_number' => 'sentinel-secret',
        ]);
        $response->assertRedirect($this->show($application))->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Pragma', 'no-cache')
            ->assertSessionHasErrors([$field => $message], null, 'decision');
        $this->assertSame(in_array($outcome, ['approved', 'rejected'], true) ? ['outcome' => $outcome] : [], session('_old_input'));
        $html = $this->get($this->show($application))->assertOk()->assertDontSee('sentinel-secret')->getContent();
        $id = $field === 'outcome' ? 'decision-outcome' : 'decision-note';
        $this->assertSame(1, substr_count($html, 'id="'.$id.'-error"'));
        $this->assertStringContainsString('aria-describedby="'.$id.'-error"', $html);
        $this->assertMatchesRegularExpression('/<textarea[^>]*name="decision_note"[^>]*><\/textarea>/', $html);
        $this->assertSame(1, substr_count($html, 'aria-invalid="true"'));
        $this->assertNoEffects();
    }

    public static function validNotes(): array
    {
        return ['minimum' => [' 1234567890 ', '1234567890'], 'maximum unicode' => [" \n".str_repeat('ن', 2000)."\t ", str_repeat('ن', 2000)], 'internal whitespace' => [" \tPrivate  deliberate\n note.\r\n", "Private  deliberate\n note."]];
    }

    #[DataProvider('validNotes')]
    public function test_trimmed_note_boundaries_encrypt_exact_content_and_are_hidden(string $input, string $expected): void
    {
        [$actor, $application] = $this->fixture();
        $this->actingAs($actor)->post($this->url($application), ['outcome' => 'approved', 'decision_note' => $input])->assertRedirect();
        $application->refresh();
        $this->assertSame($expected, $application->decision_note);
        $this->assertNotSame($expected, $application->getRawOriginal('decision_note'));
        $this->assertStringNotContainsString($expected, $application->getRawOriginal('decision_note'));
        $this->assertArrayNotHasKey('decision_note', $application->toArray());
        $this->assertSame(['*'], $application->getGuarded());
    }

    public static function outcomes(): array
    {
        return ['approval' => ['approved'], 'rejection' => ['rejected']];
    }

    #[DataProvider('outcomes')]
    public function test_exact_mutation_audit_outbox_payload_and_no_unrelated_effects(string $outcome): void
    {
        [$actor, $application] = $this->fixture();
        HelpApplicationDocument::factory()->create(['help_application_id' => $application->id]);
        InternalNotification::factory()->create();
        $before = $application->refresh()->getRawOriginal();
        $documents = DB::table('help_application_documents')->get()->toJson();
        $history = DB::table('internal_notifications')->get()->toJson();
        $category = DB::table('categories')->get()->toJson();
        $eventsBefore = InternalNotificationEvent::count();
        $intentsBefore = InternalNotificationEventRecipient::count();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        Bus::fake();
        Log::spy();
        $response = $this->actingAs($actor)->post($this->url($application), $this->payload($outcome));
        $response->assertRedirect(route('admin.help-applications.in-review.index'))->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Pragma', 'no-cache');
        $application->refresh();
        $this->assertSame($outcome, $application->status->value);
        $this->assertSame($actor->id, $application->decided_by);
        $this->assertSame($actor->id, $application->updated_by);
        $this->assertTrue($application->open_slot);
        $this->assertTrue($application->decided_at->equalTo(now()));
        $this->assertTrue($application->decided_at->equalTo($application->status_changed_at));
        $this->assertTrue($application->decided_at->equalTo($application->updated_at));
        if ($outcome === 'rejected') {
            $this->assertTrue($application->decided_at->addDays(30)->equalTo($application->appeal_eligibility_ended_at));
        } else {
            $this->assertNull($application->appeal_eligibility_ended_at);
        }
        $changedFields = ['status', 'decided_by', 'decided_at', 'decision_note', 'status_changed_at', 'updated_by', 'updated_at'];
        if ($outcome === 'rejected') {
            $changedFields[] = 'appeal_eligibility_ended_at';
        }
        $this->assertSame(array_diff_key($before, array_flip($changedFields)), array_diff_key($application->getRawOriginal(), array_flip($changedFields)));
        $audit = AuditLog::sole();
        $this->assertSame('help_application.decided', $audit->action);
        $this->assertSame($actor->id, $audit->actor_id);
        $this->assertSame($actor->name, $audit->actor_name);
        $this->assertSame($application->id, $audit->subject_id);
        $this->assertSame($application->getMorphClass(), $audit->subject_type);
        $this->assertSame(['status' => 'under_review'], $audit->old_values);
        $this->assertSame(['status' => $outcome], $audit->new_values);
        $event = InternalNotificationEvent::where('help_application_id', $application->id)->sole();
        $type = $outcome === 'approved' ? InternalNotificationEventType::HelpApplicationApproved : InternalNotificationEventType::HelpApplicationRejected;
        $this->assertSame($type, $event->type);
        $this->assertSame(app(InternalNotificationEventKey::class)->make($type, $application->id), $event->deduplication_key);
        $this->assertTrue($event->occurred_at->equalTo($application->decided_at));
        $intent = $event->recipientIntents()->sole();
        $this->assertSame($application->applicant_id, $intent->recipient_id);
        $this->assertSame('user', $intent->recipient_role->value);
        $this->assertSame('applicant', $intent->audience->value);
        $this->assertSame('help_application_'.$outcome, $intent->notification_type->value);
        $this->assertTrue($intent->available_at->equalTo($application->decided_at));
        $notification = InternalNotification::where('event_recipient_id', $intent->id)->sole();
        $this->assertSame($intent->notification_type, $notification->type);
        $this->assertSame($application->applicant_id, $notification->recipient_id);
        $this->assertSame(['application_reference' => $application->reference, 'status' => $outcome], $notification->allowlistedData());
        $this->assertDatabaseCount('internal_notification_events', $eventsBefore + 1);
        $this->assertDatabaseCount('internal_notification_event_recipients', $intentsBefore + 1);
        $this->assertSame($documents, DB::table('help_application_documents')->get()->toJson());
        $this->assertSame($category, DB::table('categories')->get()->toJson());
        $this->assertSame($history, DB::table('internal_notifications')->where('id', '!=', $notification->id)->get()->toJson());
        $this->assertDatabaseCount('help_application_duplicate_warnings', 0);
        $this->assertDatabaseCount('campaigns', 0);
        Mail::assertNothingSent();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
        $this->get(route('admin.help-applications.in-review.index'))->assertDontSee($application->reference)
            ->assertSee($outcome === 'approved' ? 'Help Application approved successfully.' : 'Help Application rejected successfully.');
        $this->get($this->show($application))->assertNotFound();
        $this->actingAs($application->applicant)->get(route('help-applications.index'))->assertOk()
            ->assertDontSee('decision_note')->assertDontSee(trim($this->payload()['decision_note']))->assertDontSee($actor->name)->assertDontSee('help_application.decided');
    }

    public static function brokenLifecycle(): array
    {
        return [
            'closed slot' => ['open_slot', null], 'missing submission' => ['submitted_at', null],
            'missing review start' => ['review_started_at', null], 'missing status time' => ['status_changed_at', null],
            'different status time' => ['status_changed_at', '2020-01-01 00:00:00'],
            'prior decider' => ['decided_by', 'actor'], 'prior decision time' => ['decided_at', '2020-01-01 00:00:00'],
            'prior raw note' => ['decision_note', 'opaque-corrupt-ciphertext'],
            'prior appeal deadline' => ['appeal_eligibility_ended_at', '2020-01-01 00:00:00'],
            'missing category' => ['category_id', null], 'missing assigner' => ['category_assigned_by', null],
            'missing assignment time' => ['category_assigned_at', null],
        ];
    }

    #[DataProvider('brokenLifecycle')]
    public function test_each_lifecycle_and_category_invariant_fails_closed_and_suppresses_form(string $field, mixed $value): void
    {
        [$actor, $application] = $this->fixture();
        DB::table('help_applications')->where('id', $application->id)->update([$field => $value === 'actor' ? $actor->id : $value]);
        $before = DB::table('help_applications')->where('id', $application->id)->first();
        $this->actingAs($actor)->get($this->show($application))->assertOk()->assertDontSee($this->url($application), false);
        foreach (['approved', 'rejected'] as $outcome) {
            $this->post($this->url($application), $this->payload($outcome))->assertNotFound();
        }
        $this->assertEquals($before, DB::table('help_applications')->where('id', $application->id)->first());
        $this->assertNoEffects();
    }

    #[DataProvider('outcomes')]
    public function test_inactive_soft_deleted_historical_category_does_not_block_decision(string $outcome): void
    {
        [$actor, $application] = $this->fixture();
        DB::table('categories')->where('id', $application->category_id)->update(['is_active' => false, 'deleted_at' => now()]);
        $this->actingAs($actor)->post($this->url($application), $this->payload($outcome))->assertRedirect();
        $this->assertSame($outcome, $application->refresh()->status->value);
    }

    private function warning(HelpApplication $application, User $actor, string $status): Warning
    {
        $warning = Warning::factory()->create(['submitted_application_id' => $application->id]);
        if ($status !== 'unreviewed') {
            // Opaque raw bytes intentionally cannot be decrypted: readiness only checks presence.
            DB::table('help_application_duplicate_warnings')->where('id', $warning->id)->update([
                'status' => $status, 'resolved_by' => $actor->id, 'resolved_at' => now(), 'resolution_note' => 'opaque-ciphertext',
            ]);
        }

        return $warning;
    }

    public static function warningCases(): array
    {
        return [
            'dismissed approval' => [['dismissed'], 'approved', true], 'dismissed rejection' => [['dismissed'], 'rejected', true],
            'unreviewed approval' => [['unreviewed'], 'approved', false], 'unreviewed rejection' => [['unreviewed'], 'rejected', false],
            'confirmed approval' => [['confirmed_match'], 'approved', false], 'confirmed rejection' => [['confirmed_match'], 'rejected', true],
            'mixed unresolved' => [['dismissed', 'confirmed_match', 'unreviewed'], 'rejected', false],
            'mixed resolved approval' => [['dismissed', 'confirmed_match'], 'approved', false],
            'mixed resolved rejection' => [['dismissed', 'confirmed_match'], 'rejected', true],
        ];
    }

    #[DataProvider('warningCases')]
    public function test_directional_warning_readiness_and_authoritative_outcome_options(array $statuses, string $outcome, bool $allowed): void
    {
        [$actor, $application] = $this->fixture();
        foreach ($statuses as $status) {
            $this->warning($application, $actor, $status);
        }
        $before = DB::table('help_application_duplicate_warnings')->get()->toJson();
        $page = $this->actingAs($actor)->get($this->show($application))->assertOk();
        if (in_array('unreviewed', $statuses, true)) {
            $page->assertDontSee($this->url($application), false)->assertSee(route('admin.help-applications.in-review.duplicate-warnings.index', $application->reference), false);
        } elseif (in_array('confirmed_match', $statuses, true)) {
            $page->assertSee('value="rejected"', false)->assertDontSee('value="approved"', false)->assertSee('A confirmed match prevents approval; rejection remains available.');
        }
        $response = $this->post($this->url($application), $this->payload($outcome));
        if ($allowed) {
            $response->assertRedirect();
        } else {
            $response->assertNotFound();
            $this->assertNoEffects();
        }
        $this->assertSame($before, DB::table('help_application_duplicate_warnings')->get()->toJson());
    }

    public function test_incoming_warning_does_not_block_the_matched_application(): void
    {
        [$actor, $application] = $this->fixture();
        Warning::factory()->create(['matched_application_id' => $application->id]);
        $this->actingAs($actor)->post($this->url($application), $this->payload())->assertRedirect();
    }

    public static function corruptWarnings(): array
    {
        return [
            'self match' => ['unreviewed', 'matched_application_id', 'application'],
            'unreviewed resolver' => ['unreviewed', 'resolved_by', 'actor'],
            'unreviewed timestamp' => ['unreviewed', 'resolved_at', '2020-01-01 00:00:00'],
            'unreviewed note' => ['unreviewed', 'resolution_note', 'opaque'],
            'missing resolver' => ['dismissed', 'resolved_by', null],
            'missing time' => ['dismissed', 'resolved_at', null],
            'missing note' => ['dismissed', 'resolution_note', null],
            'confirmed missing resolver' => ['confirmed_match', 'resolved_by', null],
            'confirmed missing time' => ['confirmed_match', 'resolved_at', null],
            'confirmed missing note' => ['confirmed_match', 'resolution_note', null],
            'unknown status' => ['dismissed', 'status', 'unknown'],
        ];
    }

    #[DataProvider('corruptWarnings')]
    public function test_corrupt_warning_states_suppress_form_and_block_both_outcomes(string $status, string $field, mixed $value): void
    {
        [$actor, $application] = $this->fixture();
        $warning = $this->warning($application, $actor, $status);
        DB::table('help_application_duplicate_warnings')->where('id', $warning->id)->update([
            $field => match ($value) {
                'application' => $application->id, 'actor' => $actor->id, default => $value
            },
        ]);
        $this->actingAs($actor)->get($this->show($application))->assertOk()->assertDontSee($this->url($application), false);
        foreach (['approved', 'rejected'] as $outcome) {
            $this->post($this->url($application), $this->payload($outcome))->assertNotFound();
        }
        $this->assertNoEffects();
    }

    public function test_first_decision_wins_for_repeated_opposite_stale_and_later_actor_attempts(): void
    {
        [$actor, $application] = $this->fixture();
        $this->actingAs($actor)->get($this->show($application))->assertOk();
        $this->post($this->url($application), $this->payload())->assertRedirect();
        $before = $application->refresh()->getRawOriginal();
        $this->post($this->url($application), $this->payload())->assertNotFound();
        $this->post($this->url($application), $this->payload('rejected'))->assertNotFound();
        $this->actingAs(User::factory()->superAdmin()->create())->post($this->url($application), $this->payload('rejected'))->assertNotFound();
        $this->assertSame($before, $application->refresh()->getRawOriginal());
        foreach (['audit_logs', 'internal_notification_events', 'internal_notification_event_recipients', 'internal_notifications'] as $table) {
            $this->assertDatabaseCount($table, 1);
        }
    }

    public static function rollbackTables(): array
    {
        return ['audit' => ['audit_logs'], 'event' => ['internal_notification_events'], 'intent' => ['internal_notification_event_recipients']];
    }

    #[DataProvider('rollbackTables')]
    public function test_transaction_rolls_back_application_audit_event_and_intent_when_durable_write_fails(string $table): void
    {
        [$actor, $application] = $this->fixture();
        $before = $application->refresh()->getRawOriginal();
        DB::statement("CREATE TRIGGER decision_write_failure BEFORE INSERT ON {$table} BEGIN SELECT RAISE(ABORT, 'unavailable'); END");
        $this->actingAs($actor)->post($this->url($application), $this->payload())->assertStatus(500);
        $this->assertSame($before, $application->refresh()->getRawOriginal());
        $this->assertNoEffects();
    }

    public function test_projection_failure_keeps_durable_decision_and_retries_exactly_once(): void
    {
        [$actor, $application] = $this->fixture();
        DB::statement("CREATE TRIGGER decision_projection_failure BEFORE INSERT ON internal_notifications BEGIN SELECT RAISE(ABORT, 'unavailable'); END");
        $this->actingAs($actor)->post($this->url($application), $this->payload())->assertRedirect();
        $intent = InternalNotificationEventRecipient::sole();
        $this->assertSame('pending', $intent->state->value);
        $this->assertSame(1, $intent->attempts);
        $this->assertSame('approved', $application->refresh()->status->value);
        $this->assertDatabaseCount('internal_notifications', 0);
        DB::statement('DROP TRIGGER decision_projection_failure');
        $this->travel(61)->seconds();
        $result = app(InternalNotificationProjector::class)->projectEvent($intent->event_id);
        $this->assertSame(1, $result->projected);
        $this->assertSame(0, app(InternalNotificationProjector::class)->projectEvent($intent->event_id)->projected);
        $this->post($this->url($application), $this->payload())->assertNotFound();
        foreach (['audit_logs', 'internal_notification_events', 'internal_notification_event_recipients', 'internal_notifications'] as $table) {
            $this->assertDatabaseCount($table, 1);
        }
    }

    public function test_notification_types_reject_cross_status_extra_keys_and_malformed_references(): void
    {
        $payloads = app(InternalNotificationPayload::class);
        $reference = (string) Str::uuid();
        foreach ([[InternalNotificationType::HelpApplicationCampaignActivated, 'campaign_active'], [InternalNotificationType::HelpApplicationApproved, 'approved'], [InternalNotificationType::HelpApplicationRejected, 'rejected'], [InternalNotificationType::HelpApplicationNewSubmission, 'pending'], [InternalNotificationType::HelpApplicationSubmissionConfirmation, 'pending']] as [$type, $expected]) {
            $this->assertSame(['application_reference' => $reference, 'status' => $expected], $payloads->build($type, $reference));
            foreach (['approved', 'rejected', 'pending'] as $status) {
                if ($status === $expected) {
                    continue;
                }
                try {
                    $payloads->validate($type, ['application_reference' => $reference, 'status' => $status]);
                    $this->fail('Cross-type status accepted.');
                } catch (InvalidArgumentException $exception) {
                    $this->assertSame('Internal notification payload is invalid.', $exception->getMessage());
                }
            }
            foreach ([['application_reference' => 'bad', 'status' => $expected], ['application_reference' => $reference, 'status' => $expected, 'decision_note' => 'private']] as $unsafe) {
                try {
                    $payloads->validate($type, $unsafe);
                    $this->fail('Unsafe payload accepted.');
                } catch (InvalidArgumentException $exception) {
                    $this->assertSame('Internal notification payload is invalid.', $exception->getMessage());
                }
            }
        }
        $keys = array_map(fn ($type) => app(InternalNotificationEventKey::class)->make($type, 42), InternalNotificationEventType::cases());
        $this->assertCount(14, array_unique($keys));
    }

    public function test_ready_form_has_one_csrf_form_blank_note_enabled_first_placeholder_and_no_initial_error_aria(): void
    {
        [$actor, $application] = $this->fixture();
        $html = $this->actingAs($actor)->get($this->show($application))->assertOk()->getContent();
        preg_match('/<section[^>]*aria-labelledby="application-decision"[^>]*>(.*?)<\/section>/s', $html, $section);
        $html = $section[1];
        $this->assertSame(1, substr_count($html, '<form '));
        $this->assertSame(1, substr_count($html, 'name="_token"'));
        $this->assertStringContainsString('<option value="">Select / اختر</option>', $html);
        $this->assertStringNotContainsString('selected', $html);
        $this->assertStringNotContainsString('aria-invalid', $html);
        $this->assertStringNotContainsString('aria-describedby="decision-', $html);
        $this->assertMatchesRegularExpression('/<textarea[^>]*name="decision_note"[^>]*><\/textarea>/', $html);
        $this->assertStringContainsString('The first successful decision is final for this stage.', $html);
    }

    public function test_every_decision_utility_exists_in_compiled_css_without_rebuilding(): void
    {
        $css = implode('', array_map('file_get_contents', glob(public_path('build/assets/*.css'))));
        $view = file_get_contents(resource_path('views/admin/help-applications/in-review/decision.blade.php'));
        preg_match_all('/class="([^"]+)"/', $view, $matches);
        $classes = array_unique(preg_split('/\s+/', implode(' ', $matches[1])));
        foreach ($classes as $class) {
            $selector = '.'.str_replace([':', '/'], ['\\:', '\\/'], $class);
            $this->assertStringContainsString($selector, $css, 'Missing compiled utility: '.$class);
        }
    }

    public function test_single_connection_stale_actor_is_reauthorized_inside_transaction(): void
    {
        [$actor, $application] = $this->fixture();
        DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);
        try {
            app(HelpApplicationDecisionService::class)->decide($actor, $application->reference, 'approved', 'A private decision.');
            $this->fail('Stale actor was authorized.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
        $this->assertNoEffects();
    }

    public function test_single_connection_change_after_request_authorization_is_rechecked_by_locked_transition(): void
    {
        [$actor, $application] = $this->fixture();
        $fired = false;
        DB::listen(function ($query) use (&$fired, $application): void {
            if (! $fired && str_starts_with(strtolower($query->sql), 'select')
                && str_contains($query->sql, 'help_applications') && str_contains($query->sql, '"reviewed_by"')) {
                $fired = true;
                DB::table('help_applications')->where('id', $application->id)->update(['status' => 'rejected']);
            }
        });
        $this->actingAs($actor)->post($this->url($application), $this->payload())->assertNotFound();
        $this->assertTrue($fired);
        $this->assertSame('rejected', $application->refresh()->status->value);
        $this->assertNull($application->decision_note);
        $this->assertNoEffects();
    }

    public function test_single_connection_changed_primary_detail_read_suppresses_decision_form(): void
    {
        [$actor, $application] = $this->fixture();
        $fired = false;
        DB::listen(function ($query) use (&$fired, $application): void {
            if (! $fired && str_starts_with(strtolower($query->sql), 'select')
                && str_contains($query->sql, 'help_applications') && str_contains($query->sql, '"private_story"')) {
                $fired = true;
                DB::table('help_applications')->where('id', $application->id)->update(['category_assigned_at' => null]);
            }
        });
        $this->actingAs($actor)->get($this->show($application))->assertOk()->assertDontSee($this->url($application), false);
        $this->assertTrue($fired);
        $this->assertNoEffects();
    }

    public function test_outbox_is_pending_inside_transaction_and_projection_waits_for_outer_commit(): void
    {
        [$actor, $application] = $this->fixture();
        DB::beginTransaction();
        try {
            app(HelpApplicationDecisionService::class)->decide($actor, $application->reference, 'rejected', 'A private decision.');
            $intent = InternalNotificationEventRecipient::sole();
            $this->assertSame('pending', $intent->state->value);
            $this->assertSame(0, $intent->attempts);
            $this->assertNull($intent->projected_at);
            $this->assertNull($intent->event->projected_at);
            $this->assertDatabaseCount('internal_notifications', 0);
        } finally {
            DB::rollBack();
        }
        $this->assertNoEffects();
        $this->assertSame('under_review', $application->refresh()->status->value);
    }

    public function test_json_guest_and_invalid_json_payload_preserve_concealment_and_input_privacy(): void
    {
        [$actor, $application] = $this->fixture();
        $this->postJson($this->url($application), $this->payload())->assertNotFound();
        $this->actingAs($actor)->postJson($this->url($application), ['outcome' => ' rejected ', 'decision_note' => 'secret', 'extra' => 'secret'])
            ->assertRedirect($this->show($application))->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame([], session('_old_input'));
        $this->assertNoEffects();
    }

    public function test_forward_migration_reverses_only_its_column_in_test_database(): void
    {
        $migration = require database_path('migrations/2026_09_10_000000_add_decision_note_to_help_applications.php');
        $before = Schema::getColumnListing('help_applications');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('help_applications', 'decision_note'));
        $this->assertSame(array_values(array_diff($before, ['decision_note'])), Schema::getColumnListing('help_applications'));
        $migration->up();
        $this->assertTrue(Schema::hasColumn('help_applications', 'decision_note'));
    }
}
