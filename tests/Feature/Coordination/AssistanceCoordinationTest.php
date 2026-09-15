<?php

namespace Tests\Feature\Coordination;

use App\Enums\AssistanceCoordinationState as State;
use App\Enums\AssistanceDeliveryMethod;
use App\Enums\InternalNotificationType;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\EnsureCoordinationAccount;
use App\Http\Middleware\PrivateCoordinationResponse;
use App\Models\AssistanceCoordination;
use App\Models\AssistanceCoordinationMessage;
use App\Models\AssistanceCoordinationTransition;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Category;
use App\Models\HelpApplication;
use App\Models\InternalNotification;
use App\Models\InternalNotificationEvent;
use App\Models\InternalNotificationEventRecipient;
use App\Models\User;
use App\Services\AssistanceCoordinationNotifications;
use App\Services\AssistanceCoordinationService;
use App\Services\AuditLogger;
use App\Services\InternalNotificationPayload;
use App\Services\InternalNotificationProjector;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AssistanceCoordinationTest extends TestCase
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
            'decided_by' => $reviewer->id, 'decision_note' => 'Private synthetic approval.', 'requested_amount' => '1000.50',
            'status_changed_at' => now()->subHours(2),
        ]);
        $campaign = Campaign::factory()->funded()->create(['help_application_id' => $application->id,
            'category_id' => $category->id, 'target_amount' => '1000.50', 'raised_amount' => '1000.50',
            'created_at' => now()->subDay(), 'published_at' => now()->subHours(2), 'funded_at' => now()->subHour()]);

        return [$reviewer, $applicant, $application, $campaign];
    }

    private function start($reviewer, $application): AssistanceCoordination
    {
        return app(AssistanceCoordinationService::class)->start($reviewer, $application->reference);
    }

    private function url(string $side, string $action, $application, $coordination = null): string
    {
        return route(($side === 'admin' ? 'admin.coordination.' : 'help-applications.coordination.').$action,
            array_filter(['helpApplication' => $application->reference, 'coordination' => $coordination?->reference]));
    }

    private function responseInput($coordination, array $extra = []): array
    {
        return array_replace(['revision' => (string) $coordination->fresh()->revision,
            'delivery_method' => 'cash_collection', 'delivery_details' => 'SYNTHETIC-ONLY تعليمات تجريبية 123456'], $extra);
    }

    private function facts(): array
    {
        return [AssistanceCoordination::count(), AssistanceCoordinationTransition::count(), AssistanceCoordinationMessage::count(),
            AuditLog::count(), InternalNotificationEvent::count(), InternalNotificationEventRecipient::count(), InternalNotification::count()];
    }

    public function test_start_is_atomic_idempotent_and_preserves_application_and_campaign(): void
    {
        [$reviewer, $applicant, $application, $campaign] = $this->fixture();
        $before = [$application->fresh()->getRawOriginal(), $campaign->fresh()->getRawOriginal()];
        $this->actingAs($reviewer)->post($this->url('admin', 'start', $application))->assertRedirect()->assertHeader('Cache-Control', 'no-store, private');
        $coordination = AssistanceCoordination::sole();
        $this->assertSame(State::AwaitingApplicant, $coordination->state);
        $this->assertNull($coordination->getRawOriginal('delivery_details'));
        $this->assertSame($reviewer->id, $coordination->started_by);
        $this->assertTrue(Str::isUuid($coordination->reference));
        $this->post($this->url('admin', 'start', $application))->assertRedirect();
        $this->assertSame([1, 1, 0, 1, 1, 1, 1], $this->facts());
        $this->assertSame($before, [$application->fresh()->getRawOriginal(), $campaign->fresh()->getRawOriginal()]);
        $this->assertSame('coordination.started', AuditLog::sole()->action);
        $this->assertSame(['state' => 'awaiting_applicant'], AuditLog::sole()->new_values);
        $this->assertSame($applicant->id, InternalNotificationEventRecipient::sole()->recipient_id);
        $this->actingAs($applicant)->get($this->url('applicant', 'show', $application, $coordination))->assertOk()
            ->assertSee('Sandbox academic demonstration')->assertSee('عرض أكاديمي تجريبي')->assertSee('name="delivery_details"', false);
    }

    public function test_full_correction_cycle_confirmed_read_only_and_status_only_events(): void
    {
        [$reviewer, $applicant, $application, $campaign] = $this->fixture();
        $before = [$application->fresh()->getRawOriginal(), $campaign->fresh()->getRawOriginal()];
        $coordination = $this->start($reviewer, $application);
        for ($cycle = 0; $cycle < 2; $cycle++) {
            $this->actingAs($applicant)->post($this->url('applicant', 'respond', $application, $coordination), $this->responseInput($coordination))->assertRedirect();
            $this->assertSame(State::ApplicantResponded, $coordination->refresh()->state);
            $this->actingAs($reviewer)->post($this->url('admin', 'correct', $application, $coordination),
                ['revision' => (string) $coordination->revision, 'body' => 'Please correct synthetic instructions.'])->assertRedirect();
            $this->assertSame(State::ChangesRequested, $coordination->refresh()->state);
        }
        $this->actingAs($applicant)->post($this->url('applicant', 'respond', $application, $coordination), $this->responseInput($coordination))->assertRedirect();
        $this->actingAs($reviewer)->post($this->url('admin', 'confirm', $application, $coordination), ['revision' => (string) $coordination->refresh()->revision])->assertRedirect();
        $this->assertTrue($coordination->refresh()->readyForFutureAidDelivery());
        $this->assertSame($reviewer->id, $coordination->confirmed_by);
        $this->assertNotNull($coordination->confirmed_at);
        $facts = $this->facts();
        foreach (['message', 'correct', 'confirm'] as $action) {
            $input = ['revision' => (string) $coordination->revision];
            if ($action !== 'confirm') {
                $input['body'] = 'Normal private message';
            }
            $this->post($this->url('admin', $action, $application, $coordination), $input)->assertNotFound();
        }
        $this->actingAs($applicant)->post($this->url('applicant', 'respond', $application, $coordination), $this->responseInput($coordination))->assertNotFound();
        $this->get($this->url('applicant', 'show', $application, $coordination))->assertOk()->assertSee('Ready for future aid delivery')
            ->assertSee('SYNTHETIC-ONLY')->assertSee('Confirmed delivery instructions — read only')->assertDontSee('name="body"', false)->assertDontSee('name="delivery_details"', false);
        $this->assertSame($facts, $this->facts());
        $this->assertSame([1, 7, 2, 7, 7, 7, 7], $facts);
        $this->assertCount(7, InternalNotificationEvent::pluck('deduplication_key')->unique());
        $this->assertSame($before, [$application->fresh()->getRawOriginal(), $campaign->fresh()->getRawOriginal()]);
        foreach (AuditLog::all() as $audit) {
            $this->assertSame(['state'], array_keys($audit->new_values));
            $this->assertNull($audit->actor_name);
        }
        foreach (InternalNotificationEvent::all() as $event) {
            app(InternalNotificationProjector::class)->projectEvent($event);
            app(InternalNotificationProjector::class)->projectEvent($event);
        }
        $this->assertDatabaseCount('internal_notifications', 7);
        foreach (InternalNotification::all() as $notification) {
            $this->assertSame(['coordination_reference', 'state'], array_keys($notification->allowlistedData()));
            $this->assertSame($coordination->reference, $notification->allowlistedData()['coordination_reference']);
        }
        $serialized = json_encode([AuditLog::all()->toArray(), InternalNotificationEvent::all()->toArray(), InternalNotification::all()->toArray(), session()->all()]);
        $this->assertStringNotContainsString('SYNTHETIC-ONLY', $serialized);
        $this->assertStringNotContainsString('Please correct', $serialized);
    }

    public function test_reviewer_null_only_super_admins_coordinate_and_receive_responses(): void
    {
        [$reviewer, $applicant, $application] = $this->fixture();
        DB::table('help_applications')->where('id', $application->id)->update(['reviewed_by' => null]);
        $super = User::factory()->superAdmin()->create();
        $otherSuper = User::factory()->superAdmin()->create();
        User::factory()->superAdmin()->disabled()->create();
        User::factory()->superAdmin()->create(['must_change_password' => true]);
        $this->actingAs($reviewer)->get($this->url('admin', 'entry', $application))->assertNotFound();
        $this->post($this->url('admin', 'start', $application))->assertNotFound();
        $coordination = $this->start($super, $application);
        $this->actingAs($applicant)->post($this->url('applicant', 'respond', $application, $coordination), $this->responseInput($coordination))->assertRedirect();
        $event = InternalNotificationEvent::where('type', 'coordination_response_submitted')->sole();
        $this->assertSame([$super->id, $otherSuper->id], $event->recipientIntents()->orderBy('recipient_id')->pluck('recipient_id')->all());
        $this->actingAs($otherSuper)->get($this->url('admin', 'show', $application, $coordination))->assertOk();
        $this->assertNull($application->fresh()->reviewed_by);
    }

    #[DataProvider('forbiddenActors')]
    public function test_private_reads_and_mutations_conceal_ineligible_actors(string $kind): void
    {
        [$reviewer, $applicant, $application] = $this->fixture();
        $coordination = $this->start($reviewer, $application);
        $side = 'admin';
        $actor = match ($kind) {
            'wrong_admin' => User::factory()->admin()->create(),
            'wrong_applicant' => User::factory()->user()->create(),
            'disabled_admin' => User::factory()->admin()->disabled()->create(),
            'disabled_applicant' => $applicant,
            'password_admin' => $reviewer,
            'password_applicant' => $applicant,
            'wrong_role_admin' => $applicant,
            'wrong_role_applicant' => $reviewer,
            default => null,
        };
        if (str_contains($kind, 'applicant')) {
            $side = 'applicant';
        }
        if (str_starts_with($kind, 'password')) {
            DB::table('users')->where('id', $actor->id)->update(['must_change_password' => true]);
        }
        if ($kind === 'disabled_applicant') {
            DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);
        }
        if ($actor) {
            $this->actingAs($actor);
        }
        $facts = $this->facts();
        $this->get($this->url($side, 'show', $application, $coordination))->assertNotFound()->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache')->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->post($this->url($side, 'message', $application, $coordination), ['revision' => '1', 'body' => 'PRIVATE-NOT-LEAKED'])->assertNotFound();
        $this->assertSame($facts, $this->facts());
        $this->assertStringNotContainsString('PRIVATE-NOT-LEAKED', json_encode(session()->all()));
    }

    public static function forbiddenActors(): array
    {
        return array_map(fn ($kind) => [$kind], ['guest', 'wrong_admin', 'wrong_applicant', 'disabled_admin', 'disabled_applicant', 'password_admin', 'password_applicant', 'wrong_role_admin', 'wrong_role_applicant']);
    }

    public function test_assigned_reviewer_and_any_super_admin_have_private_access(): void
    {
        [$reviewer, $applicant, $application] = $this->fixture();
        $coordination = $this->start($reviewer, $application);
        foreach ([$reviewer, User::factory()->superAdmin()->create()] as $actor) {
            $this->actingAs($actor)->get($this->url('admin', 'show', $application, $coordination))->assertOk();
        }
        $this->actingAs($applicant)->get($this->url('applicant', 'show', $application, $coordination))->assertOk();
        $other = User::factory()->admin()->create();
        DB::table('help_applications')->where('id', $application->id)->update(['decided_by' => $other->id, 'category_assigned_by' => $other->id]);
        DB::table('campaigns')->where('help_application_id', $application->id)->update(['created_by' => $other->id, 'updated_by' => $other->id]);
        $this->actingAs($other)->get($this->url('admin', 'show', $application, $coordination))->assertNotFound();
    }

    #[DataProvider('incoherentFunding')]
    public function test_incoherent_funding_cannot_start(string $table, string $field, mixed $value): void
    {
        [$reviewer, , $application, $campaign] = $this->fixture();
        $id = $table === 'campaigns' ? $campaign->id : $application->id;
        if ($value === 'future') {
            $value = now()->addDay();
        }
        DB::table($table)->where('id', $id)->update([$field => $value]);
        $this->actingAs($reviewer)->post($this->url('admin', 'start', $application))->assertNotFound();
        $this->assertSame([0, 0, 0, 0, 0, 0, 0], $this->facts());
    }

    public static function incoherentFunding(): array
    {
        return [
            ['campaigns', 'status', 'active'], ['campaigns', 'funded_at', null], ['campaigns', 'funded_at', 'future'],
            ['campaigns', 'raised_amount', '1000.49'], ['campaigns', 'raised_amount', '1000.51'], ['campaigns', 'published_at', null],
            ['campaigns', 'deleted_at', 'future'], ['campaigns', 'aid_delivery_started_at', 'future'], ['campaigns', 'help_application_id', null],
            ['help_applications', 'status', 'approved'], ['help_applications', 'open_slot', null], ['help_applications', 'category_id', null],
            ['help_applications', 'decided_by', null], ['help_applications', 'decision_note', null], ['help_applications', 'review_started_at', null],
            ['help_applications', 'status_changed_at', 'future'], ['help_applications', 'requested_amount', '1000.49'],
        ];
    }

    #[DataProvider('invalidResponses')]
    public function test_strict_response_validation_never_flashes_sensitive_input(array $extra): void
    {
        [$reviewer, $applicant, $application] = $this->fixture();
        $coordination = $this->start($reviewer, $application);
        $facts = $this->facts();
        $this->actingAs($applicant)->post($this->url('applicant', 'respond', $application, $coordination), $this->responseInput($coordination, $extra))
            ->assertRedirect()->assertSessionHasErrors('coordination', null, 'coordination')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame($facts, $this->facts());
        $this->assertNull($coordination->fresh()->getRawOriginal('delivery_details'));
        $this->assertArrayNotHasKey('_old_input', session()->all());
        $this->assertStringNotContainsString('SYNTHETIC-ONLY', json_encode(session()->all()));
    }

    public static function invalidResponses(): array
    {
        return [
            [['delivery_method' => ['bank_transfer']]], [['delivery_method' => 'BANK_TRANSFER']], [['delivery_method' => ' bank_transfer']],
            [['delivery_method' => 'bank_transfer ']], [['delivery_method' => 'invalid']], [['delivery_method' => 1]],
            [['delivery_details' => ['SYNTHETIC-ONLY']]], [['delivery_details' => str_repeat('x', 2001)]],
            [['delivery_details' => "bad\xFF"]], [['delivery_details' => '   ']], [['delivery_details' => 123]],
            [['delivery_details' => "abc\0def"]], [['campaign_id' => '1']], [['body' => 'SYNTHETIC-ONLY']],
            [['revision' => ['1']]], [['revision' => '01']], [['revision' => 1]],
        ];
    }

    public function test_extra_query_parameters_and_uploads_are_rejected(): void
    {
        [$reviewer, $applicant, $application] = $this->fixture();
        $coordination = $this->start($reviewer, $application);
        $facts = $this->facts();
        $this->actingAs($applicant)->post($this->url('applicant', 'respond', $application, $coordination).'?delivery_details=QUERY-SECRET', $this->responseInput($coordination))
            ->assertSessionHasErrors('coordination', null, 'coordination');
        $this->post($this->url('applicant', 'message', $application, $coordination), ['revision' => '1', 'body' => 'Normal private message', 'file' => UploadedFile::fake()->create('test.txt')])
            ->assertSessionHasErrors('coordination', null, 'coordination');
        $this->assertSame($facts, $this->facts());
        $this->assertStringNotContainsString('QUERY-SECRET', json_encode(session()->all()));
    }

    public function test_cross_references_malformed_uuids_and_changed_reviewer_are_concealed(): void
    {
        [$reviewer, $applicant, $application] = $this->fixture();
        $coordination = $this->start($reviewer, $application);
        [, , $otherApplication] = $this->fixture();
        $super = User::factory()->superAdmin()->create();
        $this->actingAs($super)->get($this->url('admin', 'show', $otherApplication, $coordination))->assertNotFound();
        $this->get('/admin/assistance-coordination/bad/not-a-uuid')->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
        DB::table('help_applications')->where('id', $application->id)->update(['reviewed_by' => $super->id]);
        $facts = $this->facts();
        try {
            app(AssistanceCoordinationService::class)->mutate($reviewer, $application->reference, $coordination->reference, true, 'message', ['revision' => '1', 'body' => 'Private']);
            $this->fail('Stale reviewer accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
        $this->assertSame($facts, $this->facts());
    }

    public function test_stale_actor_account_is_reauthorized_inside_service(): void
    {
        [$reviewer, , $application] = $this->fixture();
        DB::table('users')->where('id', $reviewer->id)->update(['is_active' => false]);
        try {
            $this->start($reviewer, $application);
            $this->fail('Disabled stale actor accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
        $this->assertSame([0, 0, 0, 0, 0, 0, 0], $this->facts());
    }

    public function test_messages_are_encrypted_immutable_escaped_and_deterministically_paginated(): void
    {
        [$reviewer, $applicant, $application] = $this->fixture();
        $coordination = $this->start($reviewer, $application);
        $service = app(AssistanceCoordinationService::class);
        for ($index = 0; $index < 22; $index++) {
            $service->mutate($applicant, $application->reference, $coordination->reference, false, 'message',
                ['revision' => (string) $coordination->fresh()->revision, 'body' => sprintf('Private-%02d <script> نص عربي', $index)]);
        }
        $message = AssistanceCoordinationMessage::orderBy('id')->first();
        $this->assertNotSame($message->body, $message->getRawOriginal('body'));
        $this->assertStringNotContainsString('Private-', $message->getRawOriginal('body'));
        $this->assertArrayNotHasKey('body', $message->toArray());
        $this->assertArrayNotHasKey('sender_id', $message->toArray());
        foreach (['save', 'delete'] as $method) {
            try {
                if ($method === 'save') {
                    $message->body = 'Edited';
                }
                $message->$method();
                $this->fail('Immutable message modified.');
            } catch (\LogicException $exception) {
                $this->assertSame('Coordination messages are immutable.', $exception->getMessage());
            }
        }
        $this->actingAs($applicant)->get($this->url('applicant', 'show', $application, $coordination))->assertOk()
            ->assertSeeInOrder(['Private-00', 'Private-19'])->assertDontSee('Private-20')->assertSee('&lt;script&gt;', false)->assertSee('dir="auto"', false);
        $this->get($this->url('applicant', 'show', $application, $coordination).'?page=2')->assertOk()->assertSeeInOrder(['Private-20', 'Private-21'])->assertDontSee('Private-00');
        $this->assertSame([1, 1, 22, 1, 23, 23, 23], $this->facts());
    }

    public function test_duplicate_and_reverse_transitions_and_stale_forms_have_no_side_effects(): void
    {
        [$reviewer, $applicant, $application] = $this->fixture();
        $coordination = $this->start($reviewer, $application);
        $this->actingAs($reviewer)->post($this->url('admin', 'confirm', $application, $coordination), ['revision' => '1'])->assertNotFound();
        $this->post($this->url('admin', 'correct', $application, $coordination), ['revision' => '1'])->assertNotFound();
        $input = $this->responseInput($coordination);
        $this->actingAs($applicant)->post($this->url('applicant', 'respond', $application, $coordination), $input)->assertRedirect();
        $facts = $this->facts();
        $this->post($this->url('applicant', 'respond', $application, $coordination), $input)->assertNotFound();
        $this->post($this->url('applicant', 'respond', $application, $coordination), $this->responseInput($coordination))->assertNotFound();
        $this->post($this->url('applicant', 'message', $application, $coordination), ['revision' => '1', 'body' => 'Stale message'])->assertNotFound();
        $this->assertSame($facts, $this->facts());
        $this->assertNotSame($input['delivery_details'], $coordination->fresh()->getRawOriginal('delivery_details'));
        $this->assertArrayNotHasKey('delivery_details', $coordination->toArray());
        $this->assertArrayNotHasKey('delivery_method', $coordination->toArray());
    }

    public function test_failed_transition_rolls_back_all_records_and_hides_exception_context(): void
    {
        [$reviewer, $applicant, $application] = $this->fixture();
        $coordination = $this->start($reviewer, $application);
        $facts = $this->facts();
        $this->mock(AuditLogger::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('SENSITIVE-EXCEPTION'));
        Log::spy();
        config(['app.debug' => true]);
        $this->actingAs($applicant)->post($this->url('applicant', 'respond', $application, $coordination), $this->responseInput($coordination))
            ->assertStatus(500)->assertDontSee('SENSITIVE-EXCEPTION')->assertDontSee('SYNTHETIC-ONLY')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame($facts, $this->facts());
        $this->assertSame(State::AwaitingApplicant, $coordination->refresh()->state);
        $this->assertNull($coordination->getRawOriginal('delivery_details'));
        Log::shouldHaveReceived('warning')->once()->with('Private coordination operation failed.');
    }

    public function test_schema_foreign_keys_unique_pairs_enums_and_guards(): void
    {
        [$reviewer, , $application] = $this->fixture();
        $coordination = $this->start($reviewer, $application);
        foreach (['assistance_coordinations', 'assistance_coordination_messages', 'assistance_coordination_transitions'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
            $this->assertNotEmpty(Schema::getForeignKeys($table));
            $this->assertNotEmpty(Schema::getIndexes($table));
        }
        $this->assertSame(['awaiting_applicant', 'applicant_responded', 'changes_requested', 'confirmed'], array_column(State::cases(), 'value'));
        $this->assertSame(['bank_transfer', 'mobile_wallet', 'cash_collection', 'in_kind', 'other'], array_column(AssistanceDeliveryMethod::cases(), 'value'));
        foreach ([new AssistanceCoordination, new AssistanceCoordinationMessage, new AssistanceCoordinationTransition] as $model) {
            $this->assertSame(['*'], $model->getGuarded());
        }
        $this->assertSame($application->id, $coordination->application->id);
        $this->assertSame($coordination->id, $application->coordination->id);
        $this->assertSame($coordination->id, $coordination->campaign->coordination->id);
        $this->assertArrayNotHasKey('application', $coordination->toArray());
        $this->assertArrayNotHasKey('coordination', $application->toArray());
        $indexes = collect(Schema::getIndexes('assistance_coordinations'));
        $this->assertFalse($indexes->contains('name', 'coordination_application_campaign_unique'));
        foreach (['help_application_id', 'campaign_id'] as $column) {
            $this->assertTrue($indexes->contains(fn ($index) => $index['unique'] && $index['columns'] === [$column]));
        }
        $raw = $coordination->getRawOriginal();
        unset($raw['id']);
        $raw['reference'] = (string) Str::uuid();
        try {
            DB::table('assistance_coordinations')->insert($raw);
            $this->fail('Duplicate pair accepted.');
        } catch (QueryException) {
            $this->assertDatabaseCount('assistance_coordinations', 1);
        }
    }

    public function test_routes_are_uuid_constrained_private_post_only_and_named_throttled(): void
    {
        $routes = collect(Route::getRoutes())->filter(fn ($route) => str_contains($route->getName() ?? '', 'coordination.'));
        $this->assertCount(10, $routes);
        foreach ($routes as $route) {
            $this->assertContains('web', $route->gatherMiddleware());
            $this->assertContains('auth', $route->gatherMiddleware());
            $this->assertContains(EnsureCoordinationAccount::class, $route->gatherMiddleware());
            $this->assertArrayHasKey('helpApplication', $route->wheres);
            if (str_contains($route->uri(), '{coordination}')) {
                $this->assertArrayHasKey('coordination', $route->wheres);
            }
            if (in_array('GET', $route->methods(), true)) {
                $this->assertContains('throttle:coordination-read', $route->gatherMiddleware());
            }
            if (! in_array('GET', $route->methods(), true)) {
                $this->assertSame(['POST'], $route->methods());
                $this->assertTrue(collect($route->gatherMiddleware())->contains(fn ($name) => str_starts_with($name, 'throttle:coordination-')));
            }
        }
    }

    public function test_public_pages_never_query_private_coordination_tables(): void
    {
        [$reviewer, $applicant, $application, $campaign] = $this->fixture();
        $coordination = $this->start($reviewer, $application);
        app(AssistanceCoordinationService::class)->mutate($applicant, $application->reference, $coordination->reference, false, 'respond', $this->responseInput($coordination));
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        foreach (['/', '/en', '/en/cases', '/en/cases/'.$campaign->slug] as $url) {
            $this->get($url)->assertOk()->assertDontSee($coordination->reference)->assertDontSee('SYNTHETIC-ONLY')->assertDontSee('Private synthetic');
        }
        $this->assertStringNotContainsString('assistance_coordination', implode(' ', $queries));
    }

    public function test_projection_waits_for_commit_and_rollback_has_no_events_or_notifications(): void
    {
        // A nested outer transaction exercises deferred callbacks in the isolated test database.
        [$reviewer, , $application] = $this->fixture();
        DB::beginTransaction();
        $coordination = $this->start($reviewer, $application);
        $this->assertDatabaseCount('internal_notifications', 0);
        $this->assertDatabaseCount('internal_notification_events', 1);
        DB::rollBack();
        $this->assertSame([0, 0, 0, 0, 0, 0, 0], $this->facts());
        DB::beginTransaction();
        $coordination = $this->start($reviewer, $application);
        $this->assertDatabaseCount('internal_notifications', 0);
        DB::commit();
        $this->assertDatabaseCount('internal_notifications', 1);
        app(InternalNotificationProjector::class)->projectEvent(InternalNotificationEvent::sole());
        $this->assertDatabaseCount('internal_notifications', 1);
        $this->assertSame($coordination->reference, InternalNotification::sole()->allowlistedData()['coordination_reference']);
    }

    public function test_malformed_notification_reference_is_cancelled_without_leakage(): void
    {
        [$reviewer, $applicant, $application] = $this->fixture();
        $coordination = $this->start($reviewer, $application);
        DB::beginTransaction();
        app(AssistanceCoordinationService::class)->mutate($applicant, $application->reference, $coordination->reference, false, 'respond', $this->responseInput($coordination));
        $event = InternalNotificationEvent::where('type', 'coordination_response_submitted')->sole();
        DB::table('internal_notification_events')->where('id', $event->id)->update(['coordination_reference' => 'bad']);
        $this->assertSame(1, app(InternalNotificationProjector::class)->projectEvent($event)->cancelled);
        // A cancelled malformed reference never exposes or projects private data.
        $this->assertDatabaseCount('internal_notifications', 1);
        DB::rollBack();
        $this->assertDatabaseCount('internal_notification_events', 1);
    }

    public function test_projection_rechecks_recipient_eligibility_and_current_reviewer(): void
    {
        [$reviewer, $applicant, $application] = $this->fixture();
        $coordination = $this->start($reviewer, $application);
        DB::beginTransaction();
        app(AssistanceCoordinationService::class)->mutate($applicant, $application->reference, $coordination->reference, false, 'respond', $this->responseInput($coordination));
        $event = InternalNotificationEvent::where('type', 'coordination_response_submitted')->sole();
        $this->assertSame([$reviewer->id], $event->recipientIntents()->pluck('recipient_id')->all());
        DB::table('users')->where('id', $reviewer->id)->update(['must_change_password' => true]);
        $result = app(InternalNotificationProjector::class)->projectEvent($event);
        $this->assertSame(1, $result->cancelled);
        $this->assertSame(0, $result->projected);
        $this->assertDatabaseCount('internal_notifications', 1);
        DB::rollBack();
    }

    public function test_migration_rollback_removes_only_added_structures(): void
    {
        $migration = require database_path('migrations/2026_09_14_000000_create_assistance_coordination_tables.php');
        $before = array_column(Schema::getTables(), 'name');
        $migration->down();
        foreach (['assistance_coordinations', 'assistance_coordination_messages', 'assistance_coordination_transitions'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
        $this->assertFalse(Schema::hasColumn('internal_notification_events', 'coordination_reference'));
        $expected = array_values(array_diff($before, ['assistance_coordinations', 'assistance_coordination_messages', 'assistance_coordination_transitions']));
        $this->assertSame($expected, array_column(Schema::getTables(), 'name'));
        $migration->up();
        $this->assertTrue(Schema::hasColumn('internal_notification_events', 'coordination_reference'));
    }

    public function test_transition_history_and_message_created_at_cannot_be_changed(): void
    {
        [$reviewer, , $application] = $this->fixture();
        $coordination = $this->start($reviewer, $application);
        $transition = $coordination->transitions()->sole();
        $this->assertSame($reviewer->id, $transition->actor_id);
        $this->assertSame(1, $transition->revision);
        $this->assertTrue(Str::isUuid($transition->reference));
        foreach (['save', 'delete'] as $method) {
            try {
                if ($method === 'save') {
                    $transition->created_at = now()->addDay();
                }
                $transition->$method();
                $this->fail('Transition changed.');
            } catch (\LogicException) {
                $this->assertDatabaseCount('assistance_coordination_transitions', 1);
            }
        }
    }

    public function test_readiness_authorization_and_list_pages_never_decrypt_delivery_details(): void
    {
        [$reviewer, $applicant, $application] = $this->fixture();
        $coordination = $this->start($reviewer, $application);
        app(AssistanceCoordinationService::class)->mutate($applicant, $application->reference, $coordination->reference, false, 'respond', $this->responseInput($coordination));
        // Invalid ciphertext would throw if a list/readiness/authorization cast decrypted it.
        DB::table('assistance_coordinations')->where('id', $coordination->id)->update(['delivery_details' => 'INVALID-CIPHERTEXT']);
        $this->actingAs($reviewer)->get(route('admin.campaigns.index'))->assertOk()->assertDontSee('INVALID-CIPHERTEXT');
        $this->actingAs($applicant)->get(route('help-applications.index'))->assertOk()->assertDontSee('INVALID-CIPHERTEXT');
        $this->assertFalse($coordination->refresh()->readyForFutureAidDelivery());
        $this->actingAs($reviewer)->post($this->url('admin', 'confirm', $application, $coordination), ['revision' => (string) $coordination->revision])->assertRedirect();
        app(AssistanceCoordinationService::class)->authorize($reviewer, $application->reference, $coordination->reference, true);
    }

    public function test_all_canonical_methods_accept_unicode_synthetic_details(): void
    {
        foreach (AssistanceDeliveryMethod::cases() as $method) {
            [$reviewer, $applicant, $application] = $this->fixture();
            $coordination = $this->start($reviewer, $application);
            $this->actingAs($applicant)->postJson($this->url('applicant', 'respond', $application, $coordination), $this->responseInput($coordination, ['delivery_method' => $method->value]))->assertRedirect();
            $this->assertSame($method, $coordination->refresh()->delivery_method);
            $this->assertSame('SYNTHETIC-ONLY تعليمات تجريبية 123456', $coordination->delivery_details);
        }
    }

    public function test_throttle_and_wrong_http_methods_do_not_mutate(): void
    {
        [$reviewer, $applicant, $application] = $this->fixture();
        $coordination = $this->start($reviewer, $application);
        $url = $this->url('applicant', 'respond', $application, $coordination);
        $this->actingAs($applicant);
        for ($index = 0; $index < 6; $index++) {
            $this->post($url, [])->assertSessionHasErrors('coordination', null, 'coordination');
        }
        $this->post($url, $this->responseInput($coordination))->assertStatus(429)->assertHeader('Cache-Control', 'no-store, private');
        foreach (['get', 'patch', 'delete'] as $method) {
            $this->$method($url)->assertStatus(405)->assertHeader('Cache-Control', 'no-store, private');
        }
        $this->assertSame(State::AwaitingApplicant, $coordination->fresh()->state);
    }

    public function test_notification_projection_failure_retries_once_without_duplicate_or_sensitive_context(): void
    {
        [$reviewer, , $application] = $this->fixture();
        Log::spy();
        InternalNotification::creating(fn () => throw new RuntimeException('PRIVATE-NOTIFICATION-EXCEPTION'));
        try {
            $coordination = $this->start($reviewer, $application);
        } finally {
            InternalNotification::flushEventListeners();
        }
        $this->assertDatabaseCount('assistance_coordinations', 1);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseCount('internal_notifications', 0);
        $intent = InternalNotificationEventRecipient::sole();
        $this->assertSame('pending', $intent->state->value);
        $this->assertSame(1, $intent->attempts);
        Log::shouldHaveReceived('warning')->once()->with('Internal notification projection failed.');
        $this->travel(60)->seconds();
        $result = app(InternalNotificationProjector::class)->projectReady();
        $this->assertSame(1, $result->projected);
        $this->assertSame(0, $result->failed);
        app(InternalNotificationProjector::class)->projectReady();
        $this->assertDatabaseCount('internal_notifications', 1);
        $this->assertSame($coordination->reference, InternalNotification::sole()->allowlistedData()['coordination_reference']);
    }

    public function test_database_rejects_invalid_states_methods_sides_uuids_and_foreign_keys(): void
    {
        [$reviewer, $applicant, $application] = $this->fixture();
        $coordination = $this->start($reviewer, $application);
        foreach ([['state' => 'invalid'], ['delivery_method' => 'invalid'], ['campaign_id' => 999999], ['help_application_id' => 999999]] as $update) {
            try {
                DB::table('assistance_coordinations')->where('id', $coordination->id)->update($update);
                $this->fail('Constraint bypassed.');
            } catch (QueryException) {
                $this->assertSame(State::AwaitingApplicant, $coordination->fresh()->state);
            }
        }
        $row = ['reference' => (string) Str::uuid(), 'coordination_id' => $coordination->id, 'sender_id' => $applicant->id,
            'sender_side' => 'applicant', 'body' => encrypt('Synthetic encrypted body'), 'created_at' => now()];
        foreach ([['sender_side' => 'invalid'], ['sender_id' => 999999], ['coordination_id' => 999999]] as $update) {
            try {
                DB::table('assistance_coordination_messages')->insert(array_replace($row, $update));
                $this->fail('Message constraint bypassed.');
            } catch (QueryException) {
                $this->assertDatabaseCount('assistance_coordination_messages', 0);
            }
        }
        DB::table('assistance_coordination_messages')->insert($row);
        try {
            DB::table('assistance_coordination_messages')->insert($row);
            $this->fail('Duplicate UUID accepted.');
        } catch (QueryException) {
            $this->assertDatabaseCount('assistance_coordination_messages', 1);
        }
        $transition = AssistanceCoordinationTransition::sole()->getRawOriginal();
        unset($transition['id']);
        $transition['reference'] = (string) Str::uuid();
        try {
            DB::table('assistance_coordination_transitions')->insert($transition);
            $this->fail('Duplicate transition revision accepted.');
        } catch (QueryException) {
            $this->assertDatabaseCount('assistance_coordination_transitions', 1);
        }
    }

    public function test_changed_campaign_link_and_malformed_dates_return_concealed_404(): void
    {
        [$reviewer, , $application, $campaign] = $this->fixture();
        $coordination = $this->start($reviewer, $application);
        $otherCampaign = Campaign::factory()->create();
        DB::table('assistance_coordinations')->where('id', $coordination->id)->update(['campaign_id' => $otherCampaign->id]);
        $this->actingAs($reviewer)->get($this->url('admin', 'show', $application, $coordination))->assertNotFound();
        $this->post($this->url('admin', 'message', $application, $coordination), ['revision' => '1', 'body' => 'Private text'])->assertNotFound();
        DB::table('assistance_coordinations')->where('id', $coordination->id)->update(['campaign_id' => $campaign->id]);
        DB::table('campaigns')->where('id', $campaign->id)->update(['funded_at' => 'malformed-date']);
        $this->get($this->url('admin', 'show', $application, $coordination))->assertNotFound();
    }

    #[DataProvider('staleAccountStates')]
    public function test_mutations_reauthorize_stale_roles_password_and_account_states(array $update): void
    {
        [$reviewer, $applicant, $application] = $this->fixture();
        $coordination = $this->start($reviewer, $application);
        DB::table('users')->where('id', $reviewer->id)->update($update);
        $facts = $this->facts();
        try {
            app(AssistanceCoordinationService::class)->mutate($reviewer, $application->reference, $coordination->reference, true, 'message', ['revision' => '1', 'body' => 'PRIVATE-STale']);
            $this->fail('Stale actor accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
        $this->actingAs($reviewer)->get($this->url('admin', 'show', $application, $coordination))->assertNotFound();
        $this->assertSame($facts, $this->facts());
    }

    public static function staleAccountStates(): array
    {
        return [[['is_active' => false]], [['must_change_password' => true]], [['role' => 'user']], [['role' => 'malformed']]];
    }

    #[DataProvider('staleAccountStates')]
    public function test_ineligible_assigned_reviewer_does_not_notify_other_administrators(array $update): void
    {
        [$reviewer, $applicant, $application] = $this->fixture();
        $coordination = $this->start($reviewer, $application);
        User::factory()->superAdmin()->create();
        User::factory()->admin()->create();
        DB::table('users')->where('id', $reviewer->id)->update($update);
        $this->actingAs($applicant)->post($this->url('applicant', 'respond', $application, $coordination), $this->responseInput($coordination))->assertRedirect();
        $event = InternalNotificationEvent::where('type', 'coordination_response_submitted')->sole();
        $this->assertSame(0, $event->recipientIntents()->count());
        $this->assertNotNull($event->projected_at);
        $this->assertDatabaseCount('internal_notifications', 1);
    }

    public function test_applicant_cannot_confirm_and_corrections_may_have_no_message(): void
    {
        [$reviewer, $applicant, $application] = $this->fixture();
        $coordination = $this->start($reviewer, $application);
        $this->actingAs($applicant)->post($this->url('applicant', 'respond', $application, $coordination), $this->responseInput($coordination))->assertRedirect();
        $facts = $this->facts();
        try {
            app(AssistanceCoordinationService::class)->mutate($applicant, $application->reference, $coordination->reference, false, 'confirm', ['revision' => (string) $coordination->fresh()->revision]);
            $this->fail('Applicant confirmed.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
        $this->assertSame($facts, $this->facts());
        $this->actingAs($reviewer)->post($this->url('admin', 'correct', $application, $coordination), ['revision' => (string) $coordination->fresh()->revision, 'body' => ''])->assertRedirect();
        $this->assertSame(State::ChangesRequested, $coordination->fresh()->state);
        $this->assertDatabaseCount('assistance_coordination_messages', 0);
    }

    public function test_message_validation_rejects_arrays_malformed_utf8_oversized_text_and_extra_fields(): void
    {
        [$reviewer, $applicant, $application] = $this->fixture();
        $coordination = $this->start($reviewer, $application);
        $this->actingAs($applicant);
        foreach ([['body' => ['bad']], ['body' => "bad\xFF"], ['body' => str_repeat('x', 2001)], ['body' => 'Normal', 'delivery_details' => 'PRIVATE-DETAIL']] as $extra) {
            $this->post($this->url('applicant', 'message', $application, $coordination), array_replace(['revision' => '1', 'body' => 'Normal'], $extra))
                ->assertSessionHasErrors('coordination', null, 'coordination');
        }
        $this->assertDatabaseCount('assistance_coordination_messages', 0);
        $this->assertStringNotContainsString('PRIVATE-DETAIL', json_encode(session()->all()));
    }

    public function test_private_entry_before_start_has_no_applicant_response_form_and_correct_links(): void
    {
        [$reviewer, $applicant, $application, $campaign] = $this->fixture();
        $this->actingAs($applicant)->get($this->url('applicant', 'entry', $application))->assertOk()
            ->assertSee('Coordination has not started')->assertDontSee('name="delivery_details"', false)->assertDontSee('name="body"', false);
        $this->get(route('help-applications.index'))->assertOk()->assertSee($this->url('applicant', 'entry', $application), false);
        $this->actingAs($reviewer)->get(route('admin.campaigns.index'))->assertOk()->assertSee($this->url('admin', 'entry', $application), false);
        $this->actingAs(User::factory()->admin()->create())->get(route('admin.campaigns.index'))->assertOk()->assertDontSee($this->url('admin', 'entry', $application), false);
        DB::table('campaigns')->where('id', $campaign->id)->update(['status' => 'active']);
        $this->actingAs($applicant)->get(route('help-applications.index'))->assertOk()->assertDontSee($this->url('applicant', 'entry', $application), false);
        $this->assertSame([0, 0, 0, 0, 0, 0, 0], $this->facts());
    }

    public function test_actual_middleware_order_preserves_session_authentication_csrf_and_concealment(): void
    {
        $this->get('/admin/assistance-coordination/'.Str::uuid())->assertNotFound();
        $route = Route::getRoutes()->getByName('admin.coordination.start');
        $middleware = app('router')->gatherRouteMiddleware($route);
        $account = array_search(EnsureCoordinationAccount::class, $middleware, true);
        $session = array_search(StartSession::class, $middleware, true);
        $auth = array_search(Authenticate::class, $middleware, true);
        $this->assertIsInt($account);
        $this->assertIsInt($session);
        $this->assertIsInt($auth);
        $this->assertLessThan($account, $session);
        $this->assertLessThan($auth, $account);
        $this->assertContains(ValidateCsrfToken::class, $middleware);
        $this->assertSame(PrivateCoordinationResponse::class, $middleware[0]);
    }

    public function test_disabling_applicant_does_not_remove_eligible_administrator_access(): void
    {
        [$reviewer, $applicant, $application] = $this->fixture();
        $coordination = $this->start($reviewer, $application);
        $this->actingAs($applicant)->post($this->url('applicant', 'respond', $application, $coordination), $this->responseInput($coordination))->assertRedirect();
        DB::table('users')->where('id', $applicant->id)->update(['is_active' => false]);
        $this->get($this->url('applicant', 'show', $application, $coordination))->assertNotFound();
        foreach ([$reviewer, User::factory()->superAdmin()->create()] as $actor) {
            $this->actingAs($actor)->get($this->url('admin', 'show', $application, $coordination))->assertOk();
        }
        $this->post($this->url('admin', 'confirm', $application, $coordination), ['revision' => (string) $coordination->fresh()->revision])->assertRedirect();
        $this->assertTrue($coordination->refresh()->readyForFutureAidDelivery());
        $event = InternalNotificationEvent::where('type', 'coordination_confirmed')->sole();
        $this->assertSame(0, $event->recipientIntents()->count());
        $this->assertNotNull($event->projected_at);
        $this->assertDatabaseCount('internal_notifications', 2);
    }

    public function test_private_read_rate_limit_preserves_headers_and_creates_no_side_effects(): void
    {
        [$reviewer, $applicant, $application] = $this->fixture();
        $this->actingAs($applicant);
        for ($index = 0; $index < 30; $index++) {
            $this->get($this->url('applicant', 'entry', $application))->assertOk();
        }
        $this->get($this->url('applicant', 'entry', $application))->assertStatus(429)->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertSame([0, 0, 0, 0, 0, 0, 0], $this->facts());
    }

    private function sendMessage($actor, $application, $coordination, bool $administrator, string $body = 'PRIVATE-SYNTHETIC-MESSAGE'): void
    {
        app(AssistanceCoordinationService::class)->mutate($actor, $application->reference, $coordination->reference, $administrator, 'message',
            ['revision' => (string) $coordination->fresh()->revision, 'body' => $body]);
    }

    public function test_distinct_administrator_messages_notify_only_applicant_with_safe_payload_and_replay_is_idempotent(): void
    {
        [$reviewer, $applicant, $application] = $this->fixture();
        $coordination = $this->start($reviewer, $application);
        User::factory()->admin()->create();
        User::factory()->superAdmin()->create();
        User::factory()->user()->create();
        for ($i = 0; $i < 2; $i++) {
            $this->sendMessage($reviewer, $application, $coordination, true, 'PRIVATE-SYNTHETIC-MESSAGE-'.$i);
            $this->assertSame($i + 1, InternalNotificationEvent::where('type', 'coordination_message_available')->count());
            $this->assertSame($i + 1, InternalNotification::where('type', 'coordination_message_available')->count());
        }
        $messages = AssistanceCoordinationMessage::all();
        foreach ($messages as $message) {
            DB::transaction(fn () => app(AssistanceCoordinationNotifications::class)->recordMessage($coordination->fresh(), $application, $message, User::all()));
        }
        foreach (InternalNotificationEvent::where('type', 'coordination_message_available')->get() as $event) {
            $this->assertSame([$applicant->id], $event->recipientIntents()->pluck('recipient_id')->all());
            app(InternalNotificationProjector::class)->projectEvent($event);
            app(InternalNotificationProjector::class)->projectEvent($event);
        }
        foreach (InternalNotification::where('type', 'coordination_message_available')->get() as $notification) {
            $this->assertSame(['coordination_reference' => $coordination->reference, 'state' => 'message_available'], $notification->allowlistedData());
        }
        $this->assertSame([1, 1, 2, 1, 3, 3, 3], $this->facts());
        $raw = json_encode([DB::table('internal_notification_events')->get(), DB::table('internal_notification_event_recipients')->get(),
            DB::table('internal_notifications')->get(), DB::table('audit_logs')->get()]);
        $this->assertStringNotContainsString('PRIVATE-SYNTHETIC-MESSAGE', $raw);
        foreach ($messages as $message) {
            $this->assertStringNotContainsString($message->getRawOriginal('body'), $raw);
        }
        $this->assertSame('coordination.started', AuditLog::sole()->action);
    }

    public function test_applicant_message_notifies_only_assigned_reviewer(): void
    {
        [$reviewer, $applicant, $application] = $this->fixture();
        $coordination = $this->start($reviewer, $application);
        User::factory()->admin()->create();
        User::factory()->superAdmin()->create();
        $this->sendMessage($applicant, $application, $coordination, false);
        $event = InternalNotificationEvent::where('type', 'coordination_message_available')->sole();
        $this->assertSame([$reviewer->id], $event->recipientIntents()->pluck('recipient_id')->all());
        $this->assertSame(1, InternalNotification::where('type', 'coordination_message_available')->count());
        $this->assertSame([1, 1, 1, 1, 2, 2, 2], $this->facts());
    }

    public function test_orphaned_applicant_message_notifies_only_eligible_super_admins(): void
    {
        [$reviewer, $applicant, $application] = $this->fixture();
        $application->forceFill(['reviewed_by' => null])->save();
        $super = User::factory()->superAdmin()->create();
        $other = User::factory()->superAdmin()->create();
        User::factory()->superAdmin()->disabled()->create();
        User::factory()->superAdmin()->create(['must_change_password' => true]);
        User::factory()->admin()->create();
        $coordination = $this->start($super, $application);
        $this->sendMessage($applicant, $application, $coordination, false);
        $event = InternalNotificationEvent::where('type', 'coordination_message_available')->sole();
        $this->assertSame([$super->id, $other->id], $event->recipientIntents()->orderBy('recipient_id')->pluck('recipient_id')->all());
        $this->assertSame(2, InternalNotification::where('type', 'coordination_message_available')->count());
    }

    #[DataProvider('staleAccountStates')]
    public function test_applicant_message_never_falls_back_from_ineligible_assigned_reviewer(array $update): void
    {
        [$reviewer, $applicant, $application] = $this->fixture();
        $coordination = $this->start($reviewer, $application);
        User::factory()->superAdmin()->create();
        User::factory()->admin()->create();
        DB::table('users')->where('id', $reviewer->id)->update($update);
        $this->sendMessage($applicant, $application, $coordination, false);
        $event = InternalNotificationEvent::where('type', 'coordination_message_available')->sole();
        $this->assertSame(0, $event->recipientIntents()->count());
        $this->assertNotNull($event->projected_at);
        $this->assertSame(0, InternalNotification::where('type', 'coordination_message_available')->count());
    }

    public function test_administrator_message_does_not_notify_disabled_applicant(): void
    {
        [$reviewer, $applicant, $application] = $this->fixture();
        $coordination = $this->start($reviewer, $application);
        $applicant->forceFill(['is_active' => false])->save();
        $this->sendMessage($reviewer, $application, $coordination, true);
        $event = InternalNotificationEvent::where('type', 'coordination_message_available')->sole();
        $this->assertSame(0, $event->recipientIntents()->count());
        $this->assertNotNull($event->projected_at);
        $this->assertSame(0, InternalNotification::where('type', 'coordination_message_available')->count());
    }

    public function test_message_projection_waits_for_commit_rechecks_reviewer_and_rollback_removes_all_writes(): void
    {
        [$reviewer, $applicant, $application] = $this->fixture();
        $coordination = $this->start($reviewer, $application);
        $before = $this->facts();
        DB::beginTransaction();
        $this->sendMessage($applicant, $application, $coordination, false);
        $this->assertSame(1, InternalNotificationEvent::where('type', 'coordination_message_available')->count());
        $this->assertSame(1, InternalNotificationEventRecipient::where('notification_type', 'coordination_message_available')->count());
        $this->assertSame(0, InternalNotification::where('type', 'coordination_message_available')->count());
        DB::rollBack();
        $this->assertSame($before, $this->facts());
        DB::beginTransaction();
        $this->sendMessage($applicant, $application, $coordination, false);
        $application->forceFill(['reviewed_by' => User::factory()->admin()->create()->id])->save();
        DB::commit();
        $this->assertSame(0, InternalNotification::where('type', 'coordination_message_available')->count());
        $this->assertSame('cancelled', InternalNotificationEventRecipient::where('notification_type', 'coordination_message_available')->sole()->state->value);
    }

    public function test_message_projection_failure_is_retryable_and_replay_remains_safe(): void
    {
        [$reviewer, $applicant, $application] = $this->fixture();
        $coordination = $this->start($reviewer, $application);
        Log::spy();
        InternalNotification::creating(fn () => throw new RuntimeException('PRIVATE-MESSAGE-PROJECTION'));
        try {
            $this->sendMessage($applicant, $application, $coordination, false);
        } finally {
            InternalNotification::flushEventListeners();
        }
        $intent = InternalNotificationEventRecipient::where('notification_type', 'coordination_message_available')->sole();
        $this->assertSame('pending', $intent->state->value);
        $this->assertSame(1, $intent->attempts);
        $this->assertSame(0, InternalNotification::where('type', 'coordination_message_available')->count());
        Log::shouldHaveReceived('warning')->once()->with('Internal notification projection failed.');
        $this->travel(60)->seconds();
        $this->assertSame(1, app(InternalNotificationProjector::class)->projectReady()->projected);
        app(InternalNotificationProjector::class)->projectReady();
        $this->assertSame([1, 1, 1, 1, 2, 2, 2], $this->facts());
        $this->assertSame(AssistanceCoordinationMessage::sole()->getRawOriginal('created_at'), InternalNotification::where('type', 'coordination_message_available')->sole()->getRawOriginal('created_at'));
    }

    public function test_every_coordination_mutation_reuses_one_exact_immutable_timestamp_when_clock_advances(): void
    {
        [$reviewer, $applicant, $application, $campaign] = $this->fixture();
        $before = [$application->fresh()->getRawOriginal(), $campaign->fresh()->getRawOriginal()];
        $this->travelTo(now()->setMicrosecond(654321));
        AssistanceCoordinationMessage::creating(fn () => $this->travel(2)->seconds());
        AssistanceCoordinationTransition::creating(fn () => $this->travel(2)->seconds());
        InternalNotificationEvent::creating(fn () => $this->travel(2)->seconds());
        try {
            DB::beginTransaction();
            $expected = now()->startOfSecond()->format('Y-m-d H:i:s');
            $coordination = $this->start($reviewer, $application);
            $this->assertSame($expected, $coordination->fresh()->getRawOriginal('started_at'));
            $this->assertMutationTimestamp($expected, false);
            foreach (['respond', 'correct', 'respond', 'message', 'confirm'] as $action) {
                $expected = now()->startOfSecond()->format('Y-m-d H:i:s');
                $administrator = in_array($action, ['correct', 'confirm'], true);
                $input = $action === 'respond' ? $this->responseInput($coordination) : ['revision' => (string) $coordination->fresh()->revision];
                if (in_array($action, ['correct', 'message'], true)) {
                    $input['body'] = 'PRIVATE-TIMESTAMP-MESSAGE';
                }
                app(AssistanceCoordinationService::class)->mutate($administrator ? $reviewer : $applicant, $application->reference, $coordination->reference, $administrator, $action, $input);
                $this->assertMutationTimestamp($expected, in_array($action, ['correct', 'message'], true), $action !== 'message');
                if ($action === 'confirm') {
                    $this->assertSame($expected, $coordination->fresh()->getRawOriginal('confirmed_at'));
                }
            }
            $this->assertSame($before, [$application->fresh()->getRawOriginal(), $campaign->fresh()->getRawOriginal()]);
        } finally {
            DB::rollBack();
            AssistanceCoordinationMessage::flushEventListeners();
            AssistanceCoordinationTransition::flushEventListeners();
            InternalNotificationEvent::flushEventListeners();
        }
    }

    private function assertMutationTimestamp(string $expected, bool $message, bool $transition = true): void
    {
        if ($message) {
            $this->assertSame($expected, AssistanceCoordinationMessage::latest('id')->firstOrFail()->getRawOriginal('created_at'));
        }
        if ($transition) {
            $this->assertSame($expected, AssistanceCoordinationTransition::latest('id')->firstOrFail()->getRawOriginal('created_at'));
            $this->assertSame($expected, AuditLog::latest('id')->firstOrFail()->getRawOriginal('created_at'));
        }
        $event = InternalNotificationEvent::latest('id')->firstOrFail();
        $this->assertSame($expected, $event->getRawOriginal('occurred_at'));
        $this->assertSame($expected, $event->getRawOriginal('created_at'));
        foreach ($event->recipientIntents as $intent) {
            $this->assertSame($expected, $intent->getRawOriginal('available_at'));
            $this->assertSame($expected, $intent->getRawOriginal('created_at'));
        }
    }

    public function test_replayed_message_post_creates_no_second_message_or_notification(): void
    {
        [$reviewer, , $application] = $this->fixture();
        $coordination = $this->start($reviewer, $application);
        $input = ['revision' => '1', 'body' => 'PRIVATE-REPLAY-MESSAGE'];
        $this->actingAs($reviewer)->post($this->url('admin', 'message', $application, $coordination), $input)->assertRedirect();
        $before = $this->facts();
        $this->post($this->url('admin', 'message', $application, $coordination), $input)->assertNotFound();
        $this->assertSame($before, $this->facts());
        $this->assertSame([1, 1, 1, 1, 2, 2, 2], $before);
    }

    public function test_message_payload_rejects_private_fields_and_noncanonical_actions(): void
    {
        $payloads = app(InternalNotificationPayload::class);
        $type = InternalNotificationType::CoordinationMessageAvailable;
        $safe = ['coordination_reference' => (string) Str::uuid(), 'state' => 'message_available'];
        $this->assertSame($safe, $payloads->build($type, $safe['coordination_reference']));
        foreach (['body', 'delivery_method', 'delivery_details', 'applicant', 'sender_id', 'message_id', 'ciphertext'] as $key) {
            try {
                $payloads->validate($type, $safe + [$key => 'PRIVATE']);
                $this->fail('Private payload accepted.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertSame('Internal notification payload is invalid.', $exception->getMessage());
            }
        }
        foreach (['confirmed', 'applicant_responded', 'PRIVATE'] as $state) {
            try {
                $payloads->validate($type, array_replace($safe, ['state' => $state]));
                $this->fail('Noncanonical message action accepted.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertSame('Internal notification payload is invalid.', $exception->getMessage());
            }
        }
    }

    public static function messageDurableFailures(): array
    {
        return [['event'], ['intent']];
    }

    #[DataProvider('messageDurableFailures')]
    public function test_failed_message_outbox_write_rolls_back_message_revision_event_and_intent(string $failure): void
    {
        [$reviewer, , $application] = $this->fixture();
        $coordination = $this->start($reviewer, $application);
        $before = $this->facts();
        $model = $failure === 'event' ? InternalNotificationEvent::class : InternalNotificationEventRecipient::class;
        $model::creating(fn () => throw new RuntimeException('PRIVATE-OUTBOX-FAILURE'));
        try {
            $this->sendMessage($reviewer, $application, $coordination, true);
            $this->fail('Durable failure ignored.');
        } catch (RuntimeException) {
            $this->assertSame($before, $this->facts());
            $this->assertSame(1, $coordination->fresh()->revision);
        } finally {
            $model::flushEventListeners();
        }
    }

    public function test_schema_individual_unique_constraints_reject_each_independent_duplicate(): void
    {
        [$reviewer, , $application] = $this->fixture();
        $coordination = $this->start($reviewer, $application);
        $otherApplication = HelpApplication::factory()->create();
        $otherCampaign = Campaign::factory()->create();
        foreach ([['campaign_id' => $otherCampaign->id], ['help_application_id' => $otherApplication->id]] as $change) {
            $raw = array_replace($coordination->getRawOriginal(), $change, ['reference' => (string) Str::uuid()]);
            unset($raw['id']);
            try {
                DB::table('assistance_coordinations')->insert($raw);
                $this->fail('Individual unique constraint missing.');
            } catch (QueryException) {
                $this->assertDatabaseCount('assistance_coordinations', 1);
            }
        }
    }

    public function test_started_at_is_immutable_and_committed_workflow_timestamps_are_exact(): void
    {
        [$reviewer, $applicant, $application] = $this->fixture();
        $coordination = $this->start($reviewer, $application);
        $started = $coordination->fresh()->getRawOriginal('started_at');
        foreach (['respond', 'message', 'correct', 'respond', 'confirm'] as $action) {
            $this->travel(7)->minutes();
            $expected = now()->startOfSecond()->format('Y-m-d H:i:s');
            $administrator = in_array($action, ['correct', 'confirm'], true);
            $input = $action === 'respond' ? $this->responseInput($coordination) : ['revision' => (string) $coordination->fresh()->revision];
            if (in_array($action, ['message', 'correct'], true)) {
                $input['body'] = 'PRIVATE-PORTABILITY-MESSAGE';
            }
            app(AssistanceCoordinationService::class)->mutate($administrator ? $reviewer : $applicant, $application->reference, $coordination->reference, $administrator, $action, $input);
            $this->assertSame($started, $coordination->fresh()->getRawOriginal('started_at'), $action);
            $this->assertMutationTimestamp($expected, in_array($action, ['message', 'correct'], true), $action !== 'message');
            $event = InternalNotificationEvent::latest('id')->firstOrFail();
            foreach ($event->recipientIntents as $intent) {
                $this->assertSame($expected, $intent->internalNotification->getRawOriginal('created_at'));
            }
            if ($action === 'confirm') {
                $this->assertSame($expected, $coordination->fresh()->getRawOriginal('confirmed_at'));
            } else {
                $this->assertNull($coordination->fresh()->confirmed_at);
            }
        }
    }

    public static function coordinationDdlDrivers(): array
    {
        return [[MySqlConnection::class, 'mysql', '8.0.36'], [MariaDbConnection::class, 'mariadb', '10.6.23'], [MariaDbConnection::class, 'mariadb', '10.11.8']];
    }

    #[DataProvider('coordinationDdlDrivers')]
    public function test_original_migration_compiles_only_application_assigned_datetime_business_columns(string $connectionClass, string $driver, string $version): void
    {
        // Compile the actual migration definitions without connecting or executing DDL.
        $connection = \Mockery::mock($connectionClass.'[getServerVersion]', [fn () => throw new RuntimeException('Schema compilation must not connect.'), 'schema_only', '', ['driver' => $driver]]);
        $connection->shouldReceive('getServerVersion')->andReturn($version);
        $connection->useDefaultSchemaGrammar();
        $definitions = [];
        $originalSchema = Schema::getFacadeRoot();
        Schema::shouldReceive('create')->times(3)->andReturnUsing(function ($name, $callback) use ($connection, &$definitions): void {
            $blueprint = new Blueprint($connection, $name);
            $blueprint->create();
            $callback($blueprint);
            $definitions[$name] = $blueprint;
        });
        Schema::shouldReceive('table')->once()->with('internal_notification_events', \Mockery::type(\Closure::class));
        try {
            $migration = require database_path('migrations/2026_09_14_000000_create_assistance_coordination_tables.php');
            $migration->up();
        } finally {
            Schema::swap($originalSchema);
        }
        foreach ($this->coordinationBusinessTimestampColumns() as $table => $fields) {
            $blueprint = $definitions[$table];
            $sql = implode("\n", $blueprint->toSql());
            foreach ($fields as $field => $nullable) {
                $column = collect($blueprint->getColumns())->firstWhere('name', $field);
                $this->assertSame('dateTime', $column->type);
                $this->assertSame($nullable, (bool) $column->nullable);
                $this->assertNull($column->default);
                $this->assertFalse((bool) $column->useCurrent);
                $this->assertFalse((bool) $column->useCurrentOnUpdate);
                $this->assertMatchesRegularExpression('/`'.$field.'` datetime '.($nullable ? 'null' : 'not null').'(?=,|\))/', $sql);
            }
            $this->assertStringNotContainsString('current_timestamp', strtolower($sql));
            $this->assertStringNotContainsString('on update', strtolower($sql));
        }
    }

    public function test_sqlite_coordination_business_timestamps_have_required_nullable_semantics_and_no_defaults(): void
    {
        foreach ($this->coordinationBusinessTimestampColumns() as $table => $fields) {
            $columns = collect(Schema::getColumns($table));
            foreach ($fields as $field => $nullable) {
                $column = $columns->firstWhere('name', $field);
                $this->assertSame('datetime', $column['type_name']);
                $this->assertSame($nullable, $column['nullable']);
                $this->assertNull($column['default']);
            }
        }
    }

    private function coordinationBusinessTimestampColumns(): array
    {
        return [
            'assistance_coordinations' => ['started_at' => false, 'confirmed_at' => true],
            'assistance_coordination_transitions' => ['created_at' => false],
            'assistance_coordination_messages' => ['created_at' => false],
        ];
    }

    public function test_transition_and_message_created_at_must_be_explicitly_supplied(): void
    {
        [$reviewer, $applicant, $application] = $this->fixture();
        $coordination = $this->start($reviewer, $application);
        $this->sendMessage($applicant, $application, $coordination, false);
        foreach ([AssistanceCoordinationTransition::latest('id')->firstOrFail(), AssistanceCoordinationMessage::sole()] as $model) {
            $this->assertFalse($model->timestamps);
            $raw = $model->getRawOriginal();
            unset($raw['id'], $raw['created_at']);
            $raw['reference'] = (string) Str::uuid();
            if ($model instanceof AssistanceCoordinationTransition) {
                $raw['revision'] = $coordination->fresh()->revision + 1;
            }
            try {
                DB::table($model->getTable())->insert($raw);
                $this->fail('Database supplied a business timestamp.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('NOT NULL constraint failed: '.$model->getTable().'.created_at', $exception->getMessage());
                $this->assertDatabaseCount($model->getTable(), 1);
            }
        }
    }

    public function test_delayed_after_commit_coordination_notifications_keep_the_original_business_timestamp(): void
    {
        [$reviewer, $applicant, $application] = $this->fixture();
        $coordination = $this->start($reviewer, $application);
        DB::beginTransaction();
        try {
            $this->sendMessage($applicant, $application, $coordination, false);
            $this->travel(3)->seconds();
            app(AssistanceCoordinationService::class)->mutate($applicant, $application->reference, $coordination->reference, false, 'respond', $this->responseInput($coordination));
            $this->travel(3)->seconds();
            app(AssistanceCoordinationService::class)->mutate($reviewer, $application->reference, $coordination->reference, true, 'confirm', ['revision' => (string) $coordination->fresh()->revision]);
            $confirmed = $coordination->fresh()->getRawOriginal('confirmed_at');
            $this->assertMutationTimestamp($confirmed, false);
            $this->assertDatabaseCount('internal_notifications', 1);
            $this->travel(30)->seconds();
            DB::commit();
        } catch (\Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }
        foreach (InternalNotificationEvent::all() as $event) {
            foreach ($event->recipientIntents as $intent) {
                $this->assertSame($event->getRawOriginal('occurred_at'), $intent->internalNotification->getRawOriginal('created_at'));
            }
        }
        $this->assertSame($confirmed, InternalNotification::where('type', 'coordination_confirmed')->sole()->getRawOriginal('created_at'));
        $this->assertSame(AssistanceCoordinationMessage::sole()->getRawOriginal('created_at'), InternalNotification::where('type', 'coordination_message_available')->sole()->getRawOriginal('created_at'));
    }

    public function test_private_page_has_accessible_bilingual_conversation_structure_without_private_duplicates(): void
    {
        [$reviewer, $applicant, $application] = $this->fixture();
        $coordination = $this->start($reviewer, $application);
        $service = app(AssistanceCoordinationService::class);
        $service->mutate($applicant, $application->reference, $coordination->reference, false, 'message',
            ['revision' => '1', 'body' => 'Applicant uniquely rendered message نص مقدم الطلب']);
        $service->mutate($reviewer, $application->reference, $coordination->reference, true, 'message',
            ['revision' => '2', 'body' => 'Administrator uniquely rendered message نص المسؤول']);

        $response = $this->actingAs($applicant)->get($this->url('applicant', 'show', $application, $coordination))->assertOk()
            ->assertSee('role="note"', false)->assertSee('role="status"', false)
            ->assertSee('lang="en"', false)->assertSee('lang="ar" dir="rtl"', false)
            ->assertSee('aria-labelledby="thread-heading"', false)
            ->assertSee('aria-labelledby="message-form-heading"', false)
            ->assertSee('label for="delivery-method"', false)
            ->assertSee('label for="delivery-details"', false)
            ->assertSee('label for="message-body"', false)
            ->assertSee('aria-describedby="message-help sandbox-warning coordination-errors"', false)
            ->assertDontSee('Private assistance coordination /', false)
            ->assertDontSee('Applicant /', false)
            ->assertDontSee('Administrator /', false);

        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, 'Applicant uniquely rendered message نص مقدم الطلب'));
        $this->assertSame(1, substr_count($html, 'Administrator uniquely rendered message نص المسؤول'));
        $this->assertSame(2, substr_count($html, '<article'));
        $this->assertSame(2, substr_count($html, '<p dir="auto"'));
        $this->assertStringNotContainsString('{!!', file_get_contents(resource_path('views/coordination/show.blade.php')));
        $this->assertStringNotContainsString('data-', file_get_contents(resource_path('views/coordination/show.blade.php')));
    }

    public function test_confirmed_page_exposes_read_only_details_without_mutation_forms(): void
    {
        [$reviewer, $applicant, $application] = $this->fixture();
        $coordination = $this->start($reviewer, $application);
        app(AssistanceCoordinationService::class)->mutate($applicant, $application->reference, $coordination->reference, false, 'respond', $this->responseInput($coordination));
        app(AssistanceCoordinationService::class)->mutate($reviewer, $application->reference, $coordination->reference, true, 'confirm',
            ['revision' => (string) $coordination->fresh()->revision]);

        $this->actingAs($applicant)->get($this->url('applicant', 'show', $application, $coordination))->assertOk()
            ->assertSee('Confirmed coordination is read-only.')
            ->assertSee('التنسيق المؤكد للقراءة فقط.')
            ->assertSee('Confirmed delivery instructions — read only')
            ->assertSee('تعليمات التسليم المؤكدة — للقراءة فقط')
            ->assertSee('SYNTHETIC-ONLY')
            ->assertDontSee('name="body"', false)
            ->assertDontSee('name="delivery_details"', false)
            ->assertDontSee('type="hidden" name="revision"', false);
    }

    public function test_confirmed_details_are_decrypted_only_for_each_eligible_private_participant_and_never_copied(): void
    {
        Log::spy();
        [$reviewer, $applicant, $application] = $this->fixture();
        $coordination = $this->start($reviewer, $application);
        $details = 'CONFIRMED-PRIVATE-DETAIL تفاصيل مؤكدة 884422';
        app(AssistanceCoordinationService::class)->mutate($applicant, $application->reference, $coordination->reference, false, 'respond',
            $this->responseInput($coordination, ['delivery_details' => $details]));
        app(AssistanceCoordinationService::class)->mutate($reviewer, $application->reference, $coordination->reference, true, 'confirm',
            ['revision' => (string) $coordination->fresh()->revision]);
        $super = User::factory()->superAdmin()->create();

        foreach ([[$reviewer, 'admin'], [$super, 'admin'], [$applicant, 'applicant']] as [$actor, $side]) {
            $response = $this->actingAs($actor)->get($this->url($side, 'show', $application, $coordination))->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')
                ->assertHeader('X-Content-Type-Options', 'nosniff')->assertSee($details)
                ->assertSee('Confirmed delivery instructions — read only')->assertSee('تعليمات التسليم المؤكدة — للقراءة فقط')
                ->assertDontSee('name="body"', false)->assertDontSee('name="delivery_details"', false)
                ->assertDontSee('type="hidden" name="revision"', false);
            $this->assertSame(1, substr_count($response->getContent(), $details));
            $this->assertDoesNotMatchRegularExpression('/<[^>]+'.preg_quote($details, '/').'[^>]*>/', $response->getContent());
        }
        $this->assertNotSame($details, $coordination->fresh()->getRawOriginal('delivery_details'));
        $privateStores = json_encode([AuditLog::all()->toArray(), InternalNotificationEvent::all()->toArray(),
            InternalNotificationEventRecipient::all()->toArray(), InternalNotification::all()->toArray(), session()->all()]);
        $this->assertStringNotContainsString($details, $privateStores);
        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $level) {
            Log::shouldNotHaveReceived($level);
        }
    }

    public static function forbiddenConfirmedReaders(): array
    {
        return array_map(fn ($kind) => [$kind], ['unrelated_applicant', 'unassigned_admin', 'disabled_reviewer', 'disabled_super', 'wrong_role_admin']);
    }

    #[DataProvider('forbiddenConfirmedReaders')]
    public function test_confirmed_details_are_authorized_before_decryption_and_denials_remain_concealed(string $kind): void
    {
        [$reviewer, $applicant, $application] = $this->fixture();
        $coordination = $this->start($reviewer, $application);
        app(AssistanceCoordinationService::class)->mutate($applicant, $application->reference, $coordination->reference, false, 'respond', $this->responseInput($coordination));
        app(AssistanceCoordinationService::class)->mutate($reviewer, $application->reference, $coordination->reference, true, 'confirm',
            ['revision' => (string) $coordination->fresh()->revision]);
        [$actor, $side] = match ($kind) {
            'unrelated_applicant' => [User::factory()->user()->create(), 'applicant'],
            'unassigned_admin' => [User::factory()->admin()->create(), 'admin'],
            'disabled_reviewer' => [$reviewer, 'admin'],
            'disabled_super' => [User::factory()->superAdmin()->disabled()->create(), 'admin'],
            'wrong_role_admin' => [$applicant, 'admin'],
        };
        if ($kind === 'disabled_reviewer') {
            DB::table('users')->where('id', $reviewer->id)->update(['is_active' => false]);
        }
        // Invalid ciphertext proves a denied request never reaches the decrypting query.
        DB::table('assistance_coordinations')->where('id', $coordination->id)->update(['delivery_details' => 'INVALID-CIPHERTEXT']);
        $this->actingAs($actor)->get($this->url($side, 'show', $application, $coordination))->assertNotFound()
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertDontSee('INVALID-CIPHERTEXT');
    }

    public function test_unconfirmed_details_remain_visible_only_on_authorized_detail_pages_with_current_actions(): void
    {
        [$reviewer, $applicant, $application] = $this->fixture();
        $coordination = $this->start($reviewer, $application);
        app(AssistanceCoordinationService::class)->mutate($applicant, $application->reference, $coordination->reference, false, 'respond', $this->responseInput($coordination));
        $this->actingAs($reviewer)->get($this->url('admin', 'show', $application, $coordination))->assertOk()
            ->assertSee('Synthetic delivery instructions')->assertSee('SYNTHETIC-ONLY')->assertSee('name="body"', false)
            ->assertSee('Request corrections')->assertSee('Confirm delivery information ready')->assertDontSee('Confirmed delivery instructions — read only');
        $this->actingAs($applicant)->get($this->url('applicant', 'show', $application, $coordination))->assertOk()
            ->assertSee('Synthetic delivery instructions')->assertSee('SYNTHETIC-ONLY')->assertSee('name="body"', false)
            ->assertDontSee('name="delivery_details"', false)->assertDontSee('Confirmed delivery instructions — read only');
    }
}
