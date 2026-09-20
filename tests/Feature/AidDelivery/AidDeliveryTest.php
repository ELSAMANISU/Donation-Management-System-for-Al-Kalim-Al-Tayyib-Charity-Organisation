<?php

namespace Tests\Feature\AidDelivery;

use App\Enums\AidDeliveryAction;
use App\Enums\AidDeliveryState as State;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\EnsureCoordinationAccount;
use App\Models\AidDelivery;
use App\Models\AidDeliveryProof;
use App\Models\AidDeliveryTransition;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Category;
use App\Models\Donation;
use App\Models\HelpApplication;
use App\Models\InternalNotification;
use App\Models\InternalNotificationEvent;
use App\Models\InternalNotificationEventRecipient;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Services\AidDeliveryService;
use App\Services\AssistanceCoordinationService;
use App\Services\InternalNotificationProjector;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AidDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->freezeSecond();
        $reviewer = User::factory()->admin()->create();
        $applicant = User::factory()->user()->create();
        $category = Category::factory()->create();
        $application = HelpApplication::factory()->campaignActive()->create([
            'applicant_id' => $applicant->id, 'reviewed_by' => $reviewer->id, 'category_id' => $category->id,
            'category_assigned_by' => $reviewer->id, 'category_assigned_at' => now()->subDays(2),
            'decided_by' => $reviewer->id, 'decision_note' => 'Synthetic approval.', 'requested_amount' => '1000.50',
            'status_changed_at' => now()->subHours(2),
        ]);
        $campaign = Campaign::factory()->funded()->create(['help_application_id' => $application->id,
            'category_id' => $category->id, 'target_amount' => '1000.50', 'raised_amount' => '1000.50',
            'created_at' => now()->subDay(), 'published_at' => now()->subHours(2), 'funded_at' => now()->subHour()]);
        $donation = new Donation;
        $donation->reference = (string) Str::uuid();
        $donation->entry_key = bin2hex(random_bytes(32));
        $donation->campaign_id = $campaign->id;
        $donation->amount = '1000.50';
        $donation->currency = 'SDG';
        $donation->status = 'succeeded';
        $donation->expires_at = now()->subHour();
        $donation->save();
        $service = app(AssistanceCoordinationService::class);
        $coordination = $service->start($reviewer, $application->reference);
        $service->mutate($applicant, $application->reference, $coordination->reference, false, 'respond', [
            'revision' => '1', 'delivery_method' => 'cash_collection', 'delivery_details' => 'SYNTHETIC-RECEIVING-PRIVATE',
        ]);
        $service->mutate($reviewer, $application->reference, $coordination->reference, true, 'confirm', ['revision' => '2']);

        return [$reviewer, $applicant, $application, $campaign, $coordination->refresh()];
    }

    private function start(array $fixture, string $amount = '400.20', ?string $key = null): AidDelivery
    {
        return app(AidDeliveryService::class)->mutate($fixture[0], $fixture[2]->reference, $fixture[4]->reference, 'start',
            ['amount' => $amount, 'currency' => 'SDG', 'entry_key' => $key ?? bin2hex(random_bytes(32))]);
    }

    private function transition(array $f, AidDelivery $delivery, string $action, array $extra = []): AidDelivery
    {
        return app(AidDeliveryService::class)->mutate($f[0], $f[2]->reference, $f[4]->reference, $action,
            ['revision' => (string) $delivery->fresh()->revision] + $extra, $delivery->reference);
    }

    private function url(array $f, string $action = 'index', ?AidDelivery $delivery = null, bool $admin = true): string
    {
        return route(($admin ? 'admin.aid-delivery.' : 'help-applications.aid-delivery.').$action,
            array_filter(['helpApplication' => $f[2]->reference, 'coordination' => $f[4]->reference, 'delivery' => $delivery?->reference]));
    }

    private function facts(): array
    {
        return [AidDelivery::count(), AidDeliveryTransition::count(), AidDeliveryProof::count(), AuditLog::count(),
            InternalNotificationEvent::count(), InternalNotificationEventRecipient::count(), InternalNotification::count()];
    }

    private function denied(callable $action): void
    {
        $before = $this->facts();
        try {
            $action();
            $this->fail('Expected concealed rejection.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertSame($before, $this->facts());
    }

    public function test_first_start_is_atomic_replay_safe_and_preserves_every_unrelated_parent_field(): void
    {
        $f = $this->fixture();
        $before = [$f[2]->fresh()->getRawOriginal(), $f[3]->fresh()->getRawOriginal()];
        $money = DB::table('donations')->get()->toJson();
        $key = str_repeat('a', 64);
        $delivery = $this->start($f, key: $key);
        $this->assertSame(State::InProgress, $delivery->state);
        $this->assertSame([1, 1, 0, 6, 4, 4, 4], $this->facts());
        $this->assertSame($delivery->id, $this->start($f, key: $key)->id);
        $this->assertSame([1, 1, 0, 6, 4, 4, 4], $this->facts());
        $this->assertSame($money, DB::table('donations')->get()->toJson());
        $this->assertDatabaseCount('payment_attempts', 0);
        $after = [$f[2]->fresh()->getRawOriginal(), $f[3]->fresh()->getRawOriginal()];
        $at = $delivery->getRawOriginal('started_at');
        $this->assertSame('aid_delivery', $after[0]['status']);
        $this->assertSame('aid_delivery', $after[1]['status']);
        $this->assertSame($at, $after[0]['status_changed_at']);
        $this->assertSame($at, $after[1]['aid_delivery_started_at']);
        foreach ([['status', 'status_changed_at', 'updated_by'], ['status', 'aid_delivery_started_at']] as $index => $fields) {
            foreach ($fields as $field) {
                unset($before[$index][$field], $after[$index][$field]);
            }
        }
        $this->assertSame($before, $after);
        $this->assertSame($at, AidDeliveryTransition::sole()->getRawOriginal('created_at'));
        foreach (AuditLog::where('id', '>', 3)->get() as $audit) {
            $this->assertSame($at, $audit->getRawOriginal('created_at'));
        }
        $notification = InternalNotification::where('type', 'aid_delivery_started')->sole();
        $this->assertSame($at, $notification->getRawOriginal('created_at'));
        $this->assertSame(['delivery_reference' => $delivery->reference, 'action' => 'started'], $notification->allowlistedData());
    }

    public function test_sequential_instalments_problem_resume_proof_and_exact_balance(): void
    {
        $f = $this->fixture();
        $delivery = $this->start($f);
        $parents = [$f[2]->fresh()->getRawOriginal(), $f[3]->fresh()->getRawOriginal()];
        $this->denied(fn () => $this->start($f));
        $this->transition($f, $delivery, 'problem', ['note' => 'PRIVATE-PROBLEM-ONLY']);
        $this->assertSame(State::Problem, $delivery->fresh()->state);
        $this->denied(fn () => $this->start($f));
        $this->denied(fn () => $this->transition($f, $delivery, 'success'));
        $this->assertDatabaseCount('aid_delivery_proofs', 0);
        $this->travel(1)->seconds();
        $this->transition($f, $delivery, 'resume');
        $this->transition($f, $delivery, 'success');
        $proof = AidDeliveryProof::sole();
        $this->assertSame($delivery->id, $proof->delivery_id);
        $this->denied(fn () => $this->transition($f, $delivery, 'success'));
        $this->denied(fn () => $this->start($f, '600.31'));
        $next = $this->start($f, '600.30');
        $this->transition($f, $next, 'success');
        $detail = app(AidDeliveryService::class)->detail($f[0], $f[2]->reference, $f[4]->reference, true);
        $this->assertSame('1000.50', $detail['delivered']);
        $this->assertSame('0.00', $detail['remaining']);
        $this->assertSame($parents, [$f[2]->fresh()->getRawOriginal(), $f[3]->fresh()->getRawOriginal()]);
        $this->assertDatabaseCount('aid_delivery_proofs', 2);
        $note = AidDeliveryTransition::where('action', 'problem_recorded')->sole();
        $this->assertSame('PRIVATE-PROBLEM-ONLY', $note->note);
        $this->assertStringNotContainsString('PRIVATE-PROBLEM-ONLY', $note->getRawOriginal('note'));
        $stores = json_encode([AuditLog::all()->toArray(), InternalNotificationEvent::all()->toArray(), InternalNotification::all()->toArray(), session()->all()]);
        foreach (['PRIVATE-PROBLEM-ONLY', 'SYNTHETIC-RECEIVING-PRIVATE', $proof->sandbox_reference, '400.20'] as $secret) {
            $this->assertStringNotContainsString($secret, $stores);
        }
    }

    public function test_private_http_forms_applicant_history_and_confirmed_coordination_remain_readable(): void
    {
        $f = $this->fixture();
        $page = $this->actingAs($f[0])->get($this->url($f))->assertOk();
        $input = ['amount' => '400.20', 'currency' => 'SDG', 'idempotency_token' => $page->viewData('startToken')];
        $this->post($this->url($f, 'start'), $input)->assertRedirect()->assertHeader('Cache-Control', 'no-store, private');
        $delivery = AidDelivery::sole();
        $this->post($this->url($f, 'start'), $input)->assertRedirect();
        $page = $this->get($this->url($f))->assertOk();
        $token = $page->viewData('tokens')[$delivery->reference]['success'];
        $this->post($this->url($f, 'success', $delivery), ['revision' => '1', 'idempotency_token' => $token])->assertRedirect();
        $this->post($this->url($f, 'success', $delivery), ['revision' => '1', 'idempotency_token' => $token])->assertNotFound();
        $this->actingAs($f[1])->get($this->url($f, 'show', $delivery, false))->assertOk()->assertSee(AidDeliveryProof::sole()->sandbox_reference)
            ->assertSee('no real financial transaction or aid transfer occurred')->assertDontSee('name="amount"', false)->assertDontSee('name="note"', false)
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('Pragma', 'no-cache')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->post($this->url($f, 'start'), $input)->assertNotFound();
        $coordinationUrl = route('help-applications.coordination.show', ['helpApplication' => $f[2]->reference, 'coordination' => $f[4]->reference]);
        $this->get($coordinationUrl)->assertOk()->assertSee('SYNTHETIC-RECEIVING-PRIVATE')->assertDontSee('name="delivery_details"', false);
        DB::table('categories')->where('id', $f[3]->category_id)->update(['is_active' => false]);
        $this->get($coordinationUrl)->assertOk();
        $this->get($this->url($f, 'index', null, false))->assertOk();
        $this->actingAs($f[0])->get($this->url($f))->assertOk()->assertDontSee('name="amount"', false);
        $this->denied(fn () => $this->start($f));
        $this->assertArrayNotHasKey('_old_input', session()->all());
    }

    public static function invalidAmounts(): array
    {
        return array_map(fn ($value) => [$value], ['0', '-1', '01', '1.001', '1e2', ' 1', '1 ', '1,00', 'NaN', '', '10000000000000000', ['1'], 1, "1\xFF"]);
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_amounts_are_rejected(mixed $amount): void
    {
        $f = $this->fixture();
        $this->denied(fn () => app(AidDeliveryService::class)->mutate($f[0], $f[2]->reference, $f[4]->reference, 'start',
            ['amount' => $amount, 'currency' => 'SDG', 'entry_key' => str_repeat('a', 64)]));
    }

    public static function incoherence(): array
    {
        return [['campaigns', 'raised_amount', '1000.49'], ['donations', 'amount', '1000.49'], ['donations', 'status', 'pending'],
            ['campaigns', 'funded_at', null], ['campaigns', 'published_at', null], ['campaigns', 'aid_delivery_started_at', 'future'],
            ['campaigns', 'status', 'active'], ['campaigns', 'completed_at', 'future'], ['campaigns', 'deleted_at', 'future'],
            ['help_applications', 'open_slot', null], ['help_applications', 'status_changed_at', 'future'], ['help_applications', 'requested_amount', '1.00'],
            ['help_applications', 'category_id', null], ['help_applications', 'decided_by', null], ['help_applications', 'decision_note', null],
            ['categories', 'is_active', false], ['assistance_coordinations', 'state', 'applicant_responded'], ['assistance_coordinations', 'confirmed_at', null],
            ['assistance_coordinations', 'delivery_details', null], ['assistance_coordinations', 'confirmed_at', 'future']];
    }

    #[DataProvider('incoherence')]
    public function test_fresh_coherence_and_succeeded_ledger_reconciliation_are_mandatory(string $table, string $field, mixed $value): void
    {
        $f = $this->fixture();
        DB::table($table)->update([$field => $value === 'future' ? now()->addDay() : $value]);
        $this->denied(fn () => $this->start($f));
    }

    public static function forbiddenActors(): array
    {
        return [['guest'], ['unassigned'], ['applicant'], ['disabled'], ['password'], ['foreign_applicant']];
    }

    #[DataProvider('forbiddenActors')]
    public function test_concealed_access_denials(string $kind): void
    {
        $f = $this->fixture();
        $actor = match ($kind) {
            'guest' => null, 'unassigned' => User::factory()->admin()->create(), 'applicant' => $f[1],
            'disabled', 'password' => $f[0], 'foreign_applicant' => User::factory()->user()->create()
        };
        if ($kind === 'disabled') {
            DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);
        }
        if ($kind === 'password') {
            DB::table('users')->where('id', $actor->id)->update(['must_change_password' => true]);
        }
        if ($actor) {
            $this->actingAs($actor);
        }
        $this->get($this->url($f, admin: $kind !== 'foreign_applicant'))->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
        $this->post($this->url($f, 'start'), ['amount' => 'PRIVATE-INPUT'])->assertNotFound();
        $this->assertStringNotContainsString('PRIVATE-INPUT', json_encode(session()->all()));
    }

    public function test_super_admin_is_eligible_and_actor_role_is_refetched_inside_mutations(): void
    {
        $f = $this->fixture();
        $f[0] = User::factory()->superAdmin()->create();
        $this->actingAs($f[0])->get($this->url($f))->assertOk();
        $delivery = $this->start($f);
        DB::table('users')->where('id', $f[0]->id)->update(['must_change_password' => true]);
        $this->denied(fn () => $this->transition($f, $delivery, 'success'));
        DB::table('users')->where('id', $f[0]->id)->update(['must_change_password' => false, 'role' => 'user']);
        $this->denied(fn () => $this->transition($f, $delivery, 'success'));
    }

    public function test_strict_http_allowlists_tokens_and_no_flash(): void
    {
        $f = $this->fixture();
        $this->actingAs($f[0]);
        $page = $this->get($this->url($f))->assertOk();
        $input = ['amount' => '400.20', 'currency' => 'SDG', 'idempotency_token' => $page->viewData('startToken')];
        foreach ([['currency' => 'USD'], ['amount' => ['400.20']], ['note' => 'PRIVATE-NOTE'], ['file' => UploadedFile::fake()->create('private.txt')]] as $extra) {
            $this->post($this->url($f, 'start'), array_replace($input, $extra))->assertSessionHasErrors('delivery', null, 'delivery');
        }
        $this->post($this->url($f, 'start').'?amount=QUERY-PRIVATE', $input)->assertSessionHasErrors('delivery', null, 'delivery');
        $this->post($this->url($f, 'start'), $input + ['entry_key' => str_repeat('a', 64)])->assertNotFound();
        $this->assertArrayNotHasKey('_old_input', session()->all());
        $session = json_encode(session()->all());
        foreach (['400.20', 'PRIVATE-NOTE', 'QUERY-PRIVATE', $input['idempotency_token']] as $private) {
            $this->assertStringNotContainsString($private, $session);
        }
        $this->travel(31)->minutes();
        $this->post($this->url($f, 'start'), $input)->assertNotFound();
        $this->assertDatabaseCount('aid_deliveries', 0);
    }

    public function test_token_cannot_cross_actor_application_or_action_and_tabs_are_independent(): void
    {
        $f = $this->fixture();
        $this->actingAs($f[0]);
        $first = $this->get($this->url($f))->viewData('startToken');
        $second = $this->get($this->url($f))->viewData('startToken');
        $this->assertNotSame($first, $second);
        $input = ['amount' => '10.00', 'currency' => 'SDG', 'idempotency_token' => $first];
        $super = User::factory()->superAdmin()->create();
        $this->actingAs($super)->post($this->url($f, 'start'), $input)->assertNotFound();
        $this->actingAs($f[0])->post($this->url($f, 'start'), $input)->assertRedirect();
        $delivery = AidDelivery::sole();
        $tokens = $this->get($this->url($f))->viewData('tokens')[$delivery->reference];
        $this->post($this->url($f, 'success', $delivery), ['revision' => '1', 'idempotency_token' => $tokens['problem']])->assertNotFound();
        $this->post($this->url($f, 'success', $delivery), ['revision' => '1', 'idempotency_token' => $tokens['success']])->assertRedirect();
        $this->post($this->url($f, 'start'), array_replace($input, ['idempotency_token' => $second]))->assertRedirect();
        $this->assertDatabaseCount('aid_deliveries', 2);
        $other = $this->fixture();
        $this->actingAs($super)->post($this->url($other, 'start'), $input)->assertNotFound();
    }

    public function test_strict_problem_notes_stale_revision_and_conflicting_replay(): void
    {
        $f = $this->fixture();
        $key = str_repeat('b', 64);
        $delivery = $this->start($f, key: $key);
        $this->denied(fn () => $this->start($f, '400.21', $key));
        foreach (['', ' ', ['note'], "bad\xFF", "bad\0note", str_repeat('x', 2001)] as $note) {
            $this->denied(fn () => $this->transition($f, $delivery, 'problem', ['note' => $note]));
        }
        $this->denied(fn () => $this->transition($f, $delivery, 'resume'));
        $this->denied(fn () => $this->transition($f, $delivery, 'success', ['note' => 'unexpected']));
        $this->transition($f, $delivery, 'problem', ['note' => '<script>PRIVATE</script>']);
        $this->denied(fn () => app(AidDeliveryService::class)->mutate($f[0], $f[2]->reference, $f[4]->reference, 'resume', ['revision' => '1'], $delivery->reference));
        $this->actingAs($f[0])->get($this->url($f))->assertOk()->assertSee('&lt;script&gt;PRIVATE&lt;/script&gt;', false)->assertDontSee('<script>PRIVATE</script>', false);
        $this->actingAs($f[1])->get($this->url($f, admin: false))->assertOk()->assertDontSee('PRIVATE');
    }

    public static function failureStores(): array
    {
        return [[AuditLog::class], [InternalNotificationEvent::class], [InternalNotificationEventRecipient::class], [AidDeliveryProof::class], [AidDeliveryTransition::class]];
    }

    #[DataProvider('failureStores')]
    public function test_failure_rolls_back_all_delivery_parent_and_outbox_writes(string $model): void
    {
        $f = $this->fixture();
        $delivery = $model === AidDeliveryProof::class ? $this->start($f) : null;
        $before = $this->facts();
        $parents = [$f[2]->fresh()->getRawOriginal(), $f[3]->fresh()->getRawOriginal()];
        $event = 'eloquent.creating: '.$model;
        Event::listen($event, fn () => throw new \RuntimeException('Injected private failure'));
        try {
            if ($delivery) {
                $this->transition($f, $delivery, 'success');
            } else {
                $this->start($f);
            }
            $this->fail('Failure was swallowed.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected private failure', $exception->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertSame($before, $this->facts());
        $this->assertSame($parents, [$f[2]->fresh()->getRawOriginal(), $f[3]->fresh()->getRawOriginal()]);
        if ($delivery) {
            $this->assertSame(State::InProgress, $delivery->fresh()->state);
        }
    }

    public function test_delayed_and_retried_projection_keeps_exact_business_time_and_one_notification(): void
    {
        $f = $this->fixture();
        DB::beginTransaction();
        $delivery = $this->start($f);
        $event = InternalNotificationEvent::where('type', 'aid_delivery_started')->sole();
        $intent = $event->recipientIntents()->sole();
        $at = AidDeliveryTransition::sole()->getRawOriginal('created_at');
        foreach ([$event->getRawOriginal('occurred_at'), $event->getRawOriginal('created_at'), $intent->getRawOriginal('created_at'), $intent->getRawOriginal('available_at')] as $value) {
            $this->assertSame($at, $value);
        }
        $this->assertDatabaseCount('internal_notifications', 3);
        $this->travel(10)->seconds();
        $listener = 'eloquent.creating: '.InternalNotification::class;
        Event::listen($listener, fn () => throw new \RuntimeException('PRIVATE-PROJECTION-FAILURE'));
        try {
            DB::commit();
        } finally {
            Event::forget($listener);
        }
        $this->assertSame(State::InProgress, $delivery->fresh()->state);
        $this->assertSame(1, $intent->fresh()->attempts);
        $this->assertDatabaseCount('internal_notifications', 3);
        $this->travel(1)->hours();
        $projector = app(InternalNotificationProjector::class);
        $projector->projectEvent($event);
        $projector->projectEvent($event);
        $notification = InternalNotification::where('type', 'aid_delivery_started')->sole();
        $this->assertSame($at, $notification->getRawOriginal('created_at'));
        $this->assertSame(2, $intent->fresh()->attempts);
        $repair = require database_path('migrations/2026_09_15_000000_correct_internal_notification_business_timestamps.php');
        $before = [$event->fresh()->getRawOriginal(), $intent->fresh()->getRawOriginal(), $notification->getRawOriginal()];
        $repair->repairHistoricalTimestamps();
        $this->assertSame($before, [$event->fresh()->getRawOriginal(), $intent->fresh()->getRawOriginal(), $notification->fresh()->getRawOriginal()]);
    }

    public static function recipientChanges(): array
    {
        return [[['is_active' => false]], [['must_change_password' => true]], [['role' => 'admin']]];
    }

    #[DataProvider('recipientChanges')]
    public function test_recipient_eligibility_is_rechecked_after_commit(array $change): void
    {
        $f = $this->fixture();
        DB::beginTransaction();
        $this->start($f);
        DB::table('users')->where('id', $f[1]->id)->update($change);
        DB::commit();
        $event = InternalNotificationEvent::where('type', 'aid_delivery_started')->sole();
        $this->assertSame('cancelled', $event->recipientIntents()->sole()->state->value);
        $this->assertDatabaseCount('internal_notifications', 3);
    }

    public function test_schema_models_immutable_history_and_database_constraints(): void
    {
        $f = $this->fixture();
        $delivery = $this->start($f);
        $this->assertSame(['in_progress', 'problem', 'simulated_delivered'], array_column(State::cases(), 'value'));
        $this->assertSame(['started', 'problem_recorded', 'resumed', 'simulated_delivered'], array_column(AidDeliveryAction::cases(), 'value'));
        $expected = [
            'aid_deliveries' => ['id', 'reference', 'coordination_id', 'amount', 'currency', 'state', 'revision', 'entry_key', 'started_by', 'started_at', 'completed_at', 'unfinished_coordination_id'],
            'aid_delivery_transitions' => ['id', 'reference', 'delivery_id', 'revision', 'state', 'action', 'actor_id', 'note', 'created_at'],
            'aid_delivery_proofs' => ['id', 'reference', 'delivery_id', 'sandbox_reference', 'generator', 'version', 'created_at'],
        ];
        foreach ($expected as $table => $columns) {
            $this->assertSame($columns, Schema::getColumnListing($table));
            foreach (Schema::getForeignKeys($table) as $fk) {
                $this->assertSame('restrict', strtolower($fk['on_delete']));
            }
            foreach (Schema::getColumns($table) as $column) {
                if (str_ends_with($column['name'], '_at')) {
                    $this->assertSame('datetime', $column['type_name']);
                    $this->assertNull($column['default']);
                }
            }
        }
        $copy = $delivery->fresh()->getRawOriginal();
        unset($copy['id'], $copy['unfinished_coordination_id']);
        $copy['reference'] = (string) Str::uuid();
        $copy['entry_key'] = str_repeat('c', 64);
        try {
            DB::table('aid_deliveries')->insert($copy);
            $this->fail('Second unfinished delivery accepted.');
        } catch (QueryException) {
            $this->assertDatabaseCount('aid_deliveries', 1);
        }
        try {
            DB::table('aid_deliveries')->where('id', $delivery->id)->update(['amount' => '0']);
            $this->fail('Zero amount accepted.');
        } catch (QueryException) {
            $this->assertSame('400.20', $delivery->fresh()->amount);
        }
        $this->transition($f, $delivery, 'success');
        foreach ([$delivery->fresh(), AidDeliveryTransition::first(), AidDeliveryProof::sole()] as $model) {
            $this->assertFalse($model->timestamps);
            $this->assertSame('reference', $model->getRouteKeyName());
            foreach (array_keys($model->getAttributes()) as $field) {
                $this->assertFalse($model->isFillable($field));
            }
            foreach (['amount', 'entry_key', 'note', 'sandbox_reference', 'delivery_id', 'coordination_id', 'actor_id'] as $field) {
                $this->assertArrayNotHasKey($field, $model->toArray());
            }
            if ($model instanceof AidDelivery) {
                continue;
            }
            foreach (['update', 'delete'] as $action) {
                try {
                    if ($action === 'delete') {
                        $model->delete();
                    } else {
                        $model->reference = (string) Str::uuid();
                        $model->save();
                    } $this->fail('Immutable record changed.');
                } catch (\LogicException) {
                    $this->assertTrue($model->fresh()->exists);
                }
            }
        }
        $this->assertSame('encrypted', (new AidDeliveryTransition)->getCasts()['note']);
        $proof = AidDeliveryProof::sole()->getRawOriginal();
        unset($proof['id']);
        $proof['reference'] = (string) Str::uuid();
        $proof['sandbox_reference'] = str_repeat('d', 64);
        try {
            DB::table('aid_delivery_proofs')->insert($proof);
            $this->fail('Duplicate proof accepted.');
        } catch (QueryException) {
            $this->assertDatabaseCount('aid_delivery_proofs', 1);
        }
        $transition = AidDeliveryTransition::first()->getRawOriginal();
        unset($transition['id']);
        $transition['reference'] = (string) Str::uuid();
        try {
            DB::table('aid_delivery_transitions')->insert($transition);
            $this->fail('Duplicate revision accepted.');
        } catch (QueryException) {
            $this->assertDatabaseCount('aid_delivery_transitions', 2);
        }
    }

    public function test_route_surface_privacy_headers_and_public_isolation(): void
    {
        $f = $this->fixture();
        $delivery = $this->start($f);
        foreach (Route::getRoutes() as $route) {
            if (! str_contains($route->getName() ?? '', 'aid-delivery.')) {
                continue;
            }
            $middleware = $route->gatherMiddleware();
            $this->assertContains('web', $middleware);
            $this->assertContains('auth', $middleware);
            $this->assertContains(EnsureCoordinationAccount::class, $middleware);
            $this->assertNotEmpty(array_filter($middleware, fn ($value) => str_starts_with($value, 'throttle:aid-delivery-')));
            foreach (['helpApplication', 'coordination'] as $parameter) {
                $this->assertArrayHasKey($parameter, $route->wheres);
            }
            if (str_starts_with($route->getName(), 'help-applications.')) {
                $this->assertSame(['GET', 'HEAD'], $route->methods());
            }
        }
        $this->actingAs($f[0]);
        foreach ([$this->url($f), '/admin/aid-delivery/not-a-uuid/not-a-uuid', $this->url($f).'?unexpected=private'] as $url) {
            $this->get($url)->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Pragma', 'no-cache')
                ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Content-Type-Options', 'nosniff');
        }
        $this->get('/en/cases/'.$f[3]->slug)->assertDontSee($delivery->reference)->assertDontSee('SYNTHETIC-RECEIVING-PRIVATE');
        $view = file_get_contents(resource_path('views/aid-delivery/show.blade.php'));
        $this->assertStringNotContainsString('{!!', $view);
        $this->assertStringNotContainsString('data-', $view);
        $facts = $this->facts();
        $this->get($this->url($f))->assertOk();
        $this->get($this->url($f))->assertOk();
        $this->assertSame($facts, $this->facts());
    }

    public function test_historical_completed_and_inactive_records_remain_readable_without_mutation_or_decryption_for_denied_actors(): void
    {
        $f = $this->fixture();
        $delivery = $this->start($f);
        $this->transition($f, $delivery, 'success');
        DB::table('campaigns')->where('id', $f[3]->id)->update(['status' => 'completed', 'completed_at' => now(), 'deleted_at' => now()]);
        DB::table('help_applications')->where('id', $f[2]->id)->update(['status' => 'completed', 'open_slot' => null]);
        DB::table('categories')->where('id', $f[3]->category_id)->update(['is_active' => false, 'deleted_at' => now()]);
        foreach ([[$f[0], true], [$f[1], false]] as [$actor, $admin]) {
            $this->actingAs($actor)->get($this->url($f, admin: $admin))->assertOk()->assertDontSee('name="amount"', false);
            $this->get(route($admin ? 'admin.coordination.show' : 'help-applications.coordination.show', ['helpApplication' => $f[2]->reference, 'coordination' => $f[4]->reference]))
                ->assertOk()->assertSee('SYNTHETIC-RECEIVING-PRIVATE')->assertDontSee('name="body"', false);
        }
        $this->denied(fn () => $this->start($f));
        DB::table('assistance_coordinations')->where('id', $f[4]->id)->update(['delivery_details' => 'INVALID-CIPHERTEXT']);
        $this->actingAs(User::factory()->admin()->create())->get($this->url($f))->assertNotFound()->assertDontSee('INVALID-CIPHERTEXT');
        $this->actingAs($f[0])->get($this->url($f))->assertOk(); // Delivery reads never decrypt receiving details.
    }

    public function test_navigation_retains_private_history_links_after_start_and_hides_them_from_unassigned_admin(): void
    {
        $f = $this->fixture();
        $this->start($f);
        $this->actingAs($f[0])->get(route('admin.campaigns.index'))->assertOk()->assertSee($this->url($f), false);
        $this->actingAs($f[1])->get(route('help-applications.index'))->assertOk()->assertSee($this->url($f, admin: false), false);
        $this->actingAs(User::factory()->admin()->create())->get(route('admin.campaigns.index'))->assertOk()->assertDontSee($this->url($f), false);
    }

    public function test_exact_fractional_succeeded_sum_ignores_failed_and_pending_and_preserves_payment_rows(): void
    {
        $f = $this->fixture();
        DB::table('campaigns')->where('id', $f[3]->id)->update(['target_amount' => '0.30', 'raised_amount' => '0.30']);
        DB::table('donations')->update(['amount' => '0.10']);
        $original = Donation::sole();
        foreach ([['0.20', 'succeeded'], ['900.00', 'pending'], ['700.00', 'failed']] as [$amount, $status]) {
            $donation = $original->replicate();
            $donation->reference = (string) Str::uuid();
            $donation->entry_key = bin2hex(random_bytes(32));
            $donation->amount = $amount;
            $donation->status = $status;
            $donation->save();
        }
        $attempt = new PaymentAttempt;
        $attempt->reference = (string) Str::uuid();
        $attempt->donation_id = $original->id;
        $attempt->provider = 'sandbox';
        $attempt->provider_reference = (string) Str::uuid();
        $attempt->status = 'succeeded';
        $attempt->save();
        $before = [DB::table('donations')->get()->toJson(), DB::table('payment_attempts')->get()->toJson()];
        $delivery = $this->start($f, '0.10');
        $this->transition($f, $delivery, 'success');
        $next = $this->start($f, '0.20');
        $this->transition($f, $next, 'success');
        $this->assertSame('0.00', app(AidDeliveryService::class)->detail($f[0], $f[2]->reference, $f[4]->reference, true)['remaining']);
        $this->denied(fn () => $this->start($f, '0.01'));
        $this->assertSame($before, [DB::table('donations')->get()->toJson(), DB::table('payment_attempts')->get()->toJson()]);
    }

    public function test_cross_links_malformed_dates_and_mutation_funding_changes_are_rejected(): void
    {
        $f = $this->fixture();
        $other = $this->fixture();
        $super = User::factory()->superAdmin()->create();
        $this->actingAs($super)->get(route('admin.aid-delivery.index', ['helpApplication' => $f[2]->reference, 'coordination' => $other[4]->reference]))->assertNotFound();
        $delivery = $this->start($f);
        $otherDelivery = $this->start($other);
        $this->get($this->url($f, 'show', $otherDelivery))->assertNotFound();
        DB::table('campaigns')->where('id', $f[3]->id)->update(['funded_at' => 'malformed-date']);
        $this->denied(fn () => $this->transition($f, $delivery, 'success'));
        DB::table('campaigns')->where('id', $f[3]->id)->update(['funded_at' => $f[3]->funded_at]);
        DB::table('donations')->where('campaign_id', $f[3]->id)->update(['amount' => '999.50']);
        $this->denied(fn () => $this->transition($f, $delivery, 'success'));
    }

    public function test_http_failure_has_private_generic_body_and_does_not_log_or_flash_private_values(): void
    {
        $f = $this->fixture();
        $delivery = $this->start($f);
        $this->actingAs($f[0]);
        $tokens = $this->get($this->url($f))->viewData('tokens')[$delivery->reference];
        Log::spy();
        $listener = 'eloquent.creating: '.AidDeliveryTransition::class;
        Event::listen($listener, fn () => throw new \RuntimeException('PRIVATE-EXCEPTION-DETAIL'));
        try {
            $this->post($this->url($f, 'problem', $delivery), ['revision' => '1', 'note' => 'PRIVATE-PROBLEM-TEXT', 'idempotency_token' => $tokens['problem']])
                ->assertStatus(500)->assertDontSee('PRIVATE-EXCEPTION-DETAIL')->assertDontSee('PRIVATE-PROBLEM-TEXT')->assertHeader('Cache-Control', 'no-store, private');
        } finally {
            Event::forget($listener);
        }
        $this->assertStringNotContainsString('PRIVATE-PROBLEM-TEXT', json_encode(session()->all()));
        $this->assertArrayNotHasKey('_old_input', session()->all());
        Log::shouldHaveReceived('warning')->once()->with('Private coordination operation failed.');
        $this->assertSame(State::InProgress, $delivery->fresh()->state);
    }

    public function test_actual_middleware_order_csrf_and_independent_throttles(): void
    {
        $f = $this->fixture();
        $this->get('/admin/aid-delivery/'.Str::uuid().'/'.Str::uuid())->assertNotFound();
        $route = Route::getRoutes()->getByName('admin.aid-delivery.start');
        $middleware = app('router')->gatherRouteMiddleware($route);
        $account = array_search(EnsureCoordinationAccount::class, $middleware, true);
        $session = array_search(StartSession::class, $middleware, true);
        $auth = array_search(Authenticate::class, $middleware, true);
        $this->assertLessThan($account, $session);
        $this->assertLessThan($auth, $account);
        $this->assertContains(ValidateCsrfToken::class, $middleware);
        $this->actingAs($f[0]);
        $token = $this->get($this->url($f))->viewData('startToken');
        $input = ['amount' => '400.20', 'currency' => 'SDG', 'idempotency_token' => $token];
        for ($i = 0; $i < 6; $i++) {
            $this->post($this->url($f, 'start'), $input)->assertRedirect();
        }
        $this->post($this->url($f, 'start'), $input)->assertStatus(429)->assertHeader('Cache-Control', 'no-store, private');
        $this->get($this->url($f))->assertOk();
        $this->assertDatabaseCount('aid_deliveries', 1);
        $this->assertDatabaseCount('aid_delivery_transitions', 1);
    }

    public function test_business_dates_are_required_without_database_defaults(): void
    {
        $f = $this->fixture();
        $delivery = $this->start($f);
        $this->transition($f, $delivery, 'success');
        foreach ([AidDeliveryTransition::latest('id')->first(), AidDeliveryProof::sole()] as $model) {
            $raw = $model->getRawOriginal();
            unset($raw['id'], $raw['created_at']);
            $raw['reference'] = (string) Str::uuid();
            if ($model instanceof AidDeliveryTransition) {
                $raw['revision'] = 3;
            } else {
                $raw['delivery_id'] = $this->start($f, '0.01')->id;
                $raw['sandbox_reference'] = bin2hex(random_bytes(32));
            }
            try {
                DB::table($model->getTable())->insert($raw);
                $this->fail('Database generated a business timestamp.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('NOT NULL constraint failed: '.$model->getTable().'.created_at', $e->getMessage());
            }
        }
    }
}
