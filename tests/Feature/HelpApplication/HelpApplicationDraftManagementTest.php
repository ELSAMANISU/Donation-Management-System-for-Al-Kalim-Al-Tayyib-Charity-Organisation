<?php

namespace Tests\Feature\HelpApplication;

use App\Enums\HelpApplicationStatus;
use App\Enums\IdentityDocumentType;
use App\Enums\PublicIdentityPreference;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\HelpApplication;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\HelpApplicationDraftService;
use App\Services\IdentityBlindIndex;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class HelpApplicationDraftManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_routes_require_authentication_and_have_exact_methods_middleware_and_uuid_binding(): void
    {
        foreach ([
            ['get', route('help-applications.index')],
            ['get', route('help-applications.create')],
            ['post', route('help-applications.store')],
            ['get', '/help-applications/'.Str::uuid().'/edit'],
            ['patch', '/help-applications/'.Str::uuid()],
        ] as [$method, $uri]) {
            $this->{$method}($uri)->assertRedirect(route('login'));
        }

        $routes = collect(app('router')->getRoutes())->filter(fn ($route) => str_starts_with((string) $route->getName(), 'help-applications.'));
        $this->assertSame(5, $routes->count());
        $this->assertContains('throttle:6,1', $routes->firstWhere('action.as', 'help-applications.store')->gatherMiddleware());
        $this->assertContains('throttle:10,1', $routes->firstWhere('action.as', 'help-applications.update')->gatherMiddleware());
        $this->assertContains('role:user', $routes->firstWhere('action.as', 'help-applications.index')->gatherMiddleware());

        $user = User::factory()->create();
        $application = HelpApplication::factory()->for($user, 'applicant')->create();
        $this->actingAs($user)->get(route('help-applications.edit', $application))->assertOk();
        $this->actingAs($user)->get('/help-applications/'.$application->id.'/edit')->assertNotFound();
        $this->actingAs($user)->get('/help-applications/create')->assertRedirect(route('help-applications.edit', $application));
    }

    public function test_only_active_ordinary_users_without_pending_password_change_can_use_the_workflow(): void
    {
        foreach ([UserRole::Admin, UserRole::SuperAdmin] as $role) {
            $actor = User::factory()->create(['role' => $role]);
            $this->actingAs($actor)->get(route('help-applications.index'))->assertForbidden();
            $this->actingAs($actor)->post(route('help-applications.store'))->assertForbidden();
        }

        $disabled = User::factory()->disabled()->create();
        $this->actingAs($disabled)->get(route('help-applications.index'))->assertRedirect(route('login'));

        $flagged = User::factory()->mustChangePassword()->create();
        $this->actingAs($flagged)->get(route('help-applications.index'))->assertRedirect(route('password.change.required.edit'));
    }

    public function test_foreign_non_draft_and_closed_slot_drafts_are_denied(): void
    {
        $owner = User::factory()->create();
        $foreign = User::factory()->create();
        $draft = HelpApplication::factory()->for($owner, 'applicant')->create();
        $this->actingAs($foreign)->get(route('help-applications.edit', $draft))->assertForbidden();
        $this->actingAs($foreign)->patch(route('help-applications.update', $draft), [])->assertForbidden();

        foreach (array_filter(HelpApplicationStatus::cases(), fn ($status) => $status !== HelpApplicationStatus::Draft) as $status) {
            $application = HelpApplication::factory()->for(User::factory(), 'applicant')->create([
                'status' => $status,
                'open_slot' => $status->isOpen() ? true : null,
            ]);
            $this->actingAs($application->applicant)->get(route('help-applications.edit', $application))->assertForbidden();
            $this->actingAs($application->applicant)->patch(route('help-applications.update', $application), [])->assertForbidden();
        }

        $closedSlotDraft = HelpApplication::factory()->create(['open_slot' => null]);
        $this->actingAs($closedSlotDraft->applicant)->get(route('help-applications.edit', $closedSlotDraft))->assertForbidden();
    }

    public function test_minimal_empty_draft_has_exact_server_controlled_defaults_and_safe_audit(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->post(route('help-applications.store'), [
            'status' => 'completed', 'open_slot' => null, 'category_id' => 999,
            'applicant_id' => 999, 'identity_blind_index' => str_repeat('a', 64),
            'consent_version' => 'injected', 'updated_by' => 999,
        ]);

        $application = HelpApplication::query()->sole();
        $response->assertRedirect(route('help-applications.edit', $application));
        $this->assertTrue(Str::isUuid($application->reference));
        $this->assertSame($user->id, $application->applicant_id);
        $this->assertSame(HelpApplicationStatus::Draft, $application->status);
        $this->assertTrue($application->open_slot);
        $this->assertSame($user->id, $application->updated_by);
        foreach (['category_id', 'consent_version', 'consented_at', 'identity_blind_index', 'identity_blind_index_version', 'submitted_at', 'reviewed_by', 'decided_by'] as $field) {
            $this->assertNull($application->{$field});
        }

        $audit = AuditLog::query()->sole();
        $this->assertSame('help_application.draft_created', $audit->action);
        $this->assertSame($user->id, $audit->actor_id);
        $this->assertSame($application->id, $audit->subject_id);
        $this->assertNull($audit->old_values);
        $this->assertSame(['status' => 'draft', 'open_slot' => true], $audit->new_values);
    }

    public function test_complete_draft_normalizes_exact_values_encrypts_private_fields_and_computes_blind_index(): void
    {
        Log::spy();
        $this->configureBlindIndex();
        $user = User::factory()->create();
        $payload = $this->completePayload();
        $identityNumber = $payload['identity_document_number'];
        $response = $this->actingAs($user)->post(route('help-applications.store'), $payload);
        $response->assertSessionHasNoErrors();
        $application = HelpApplication::query()->sole();

        $this->assertSame('123.40', $application->requested_amount);
        $this->assertSame('SD', $application->identity_issuing_country);
        $this->assertSame(IdentityDocumentType::Passport, $application->identity_document_type);
        $this->assertSame(PublicIdentityPreference::Anonymous, $application->public_identity_preference);
        $this->assertSame(2, $application->identity_blind_index_version);
        $this->assertSame(64, strlen($application->identity_blind_index));

        $raw = DB::table('help_applications')->where('id', $application->id)->first();
        foreach (['full_name', 'email', 'phone', 'address', 'date_of_birth', 'identity_document_number', 'private_story', 'preferred_receiving_method'] as $field) {
            $this->assertStringNotContainsString((string) $application->{$field}, $raw->{$field});
        }

        $audit = AuditLog::query()->sole();
        $this->assertStringNotContainsString($identityNumber, serialize($audit->getAttributes()));
        $this->assertStringNotContainsString($identityNumber, serialize($audit->old_values));
        $this->assertStringNotContainsString($identityNumber, serialize($audit->new_values));
        $this->assertStringNotContainsString($identityNumber, route('help-applications.edit', $application));
        $this->assertStringNotContainsString($identityNumber, (string) $response->headers->get('Location'));
        $this->assertStringNotContainsString($identityNumber, $response->getContent());
        $this->assertStringNotContainsString($identityNumber, serialize(session()->getOldInput()));
        $this->assertStringNotContainsString($identityNumber, serialize(session()->all()));
        $this->get(route('help-applications.edit', $application))->assertDontSee($identityNumber);
        $this->assertNoApplicationLogCalls();
    }

    public function test_one_open_application_precheck_returns_bilingual_validation(): void
    {
        $user = User::factory()->create();
        HelpApplication::factory()->for($user, 'applicant')->create();

        $this->actingAs($user)->post(route('help-applications.store'))
            ->assertSessionHasErrors('help_application');
        $this->assertSame(1, HelpApplication::count());
        $this->assertSame(0, AuditLog::count());
    }

    public function test_exact_open_slot_race_is_translated_and_unrelated_query_errors_are_not(): void
    {
        $user = User::factory()->create();
        DB::statement("CREATE TRIGGER concurrent_help_application BEFORE INSERT ON help_applications BEGIN SELECT RAISE(ABORT, 'UNIQUE constraint failed: help_applications.applicant_id, help_applications.open_slot'); END");

        $this->actingAs($user)->from(route('help-applications.create'))->post(route('help-applications.store'))
            ->assertRedirect(route('help-applications.create'))
            ->assertSessionHasErrors('help_application');
        $this->assertSame(0, HelpApplication::count());

        DB::statement('DROP TRIGGER concurrent_help_application');
        DB::statement("CREATE TRIGGER unrelated_help_application BEFORE INSERT ON help_applications BEGIN SELECT RAISE(ABORT, 'NOT NULL constraint failed: help_applications.reference'); END");
        $this->withoutExceptionHandling();
        $this->expectException(QueryException::class);
        $this->actingAs($user)->post(route('help-applications.store'));
    }

    public function test_service_reloads_and_reauthorizes_stale_actor_and_application_rows(): void
    {
        $actor = User::factory()->create();
        DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);

        $this->assertThrows(
            fn () => app(HelpApplicationDraftService::class)->create($actor, [], Request::create('/help-applications', 'POST')),
            AuthorizationException::class,
        );
        $this->assertSame(0, HelpApplication::count());

        DB::table('users')->where('id', $actor->id)->update(['is_active' => true]);
        $application = HelpApplication::factory()->for($actor, 'applicant')->create();
        DB::table('help_applications')->where('id', $application->id)->update(['status' => HelpApplicationStatus::Pending->value]);

        $this->assertThrows(
            fn () => app(HelpApplicationDraftService::class)->update(
                $actor,
                $application,
                $this->updatePayload($application),
                false,
                Request::create('/help-applications/'.$application->reference, 'PATCH'),
            ),
            AuthorizationException::class,
        );
    }

    #[DataProvider('validAmounts')]
    public function test_exact_amounts_are_accepted_and_normalized(string $input, string $expected): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('help-applications.store'), ['requested_amount' => $input])->assertSessionHasNoErrors();
        $this->assertSame($expected, HelpApplication::query()->sole()->requested_amount);
    }

    public static function validAmounts(): array
    {
        return [['1', '1.00'], ['1.2', '1.20'], ['1.23', '1.23'], ['9999999999999999.99', '9999999999999999.99']];
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_amounts_are_rejected(mixed $input): void
    {
        $this->actingAs(User::factory()->create())->post(route('help-applications.store'), ['requested_amount' => $input])
            ->assertSessionHasErrors('requested_amount');
        $this->assertSame(0, HelpApplication::count());
    }

    public static function invalidAmounts(): array
    {
        return [[0], [1], [1.2], ['0'], ['0.00'], ['+1'], ['-1'], ['1,00'], ['1e2'], ['01'], ['1.234'], ['10000000000000000'], [['1.00']]];
    }

    public function test_nullable_string_length_enum_and_date_boundaries_are_enforced(): void
    {
        $boundaries = [
            'full_name' => 255,
            'phone' => 50,
            'address' => 2000,
            'identity_document_number' => 255,
            'private_story' => 20000,
            'preferred_receiving_method' => 2000,
        ];

        foreach ($boundaries as $field => $maximum) {
            $acceptedUser = User::factory()->create();
            $this->actingAs($acceptedUser)->post(route('help-applications.store'), [$field => str_repeat('x', $maximum)])
                ->assertSessionHasNoErrors();

            $rejectedUser = User::factory()->create();
            $this->actingAs($rejectedUser)->post(route('help-applications.store'), [$field => str_repeat('x', $maximum + 1)])
                ->assertSessionHasErrors($field);
        }

        $validEmail = str_repeat('a', 60).'@'.str_repeat('b', 60).'.'.str_repeat('c', 60).'.'.str_repeat('d', 60).'.com';
        $this->actingAs(User::factory()->create())->post(route('help-applications.store'), ['email' => $validEmail])
            ->assertSessionHasNoErrors();
        $this->actingAs(User::factory()->create())->post(route('help-applications.store'), ['email' => str_repeat('a', 256).'@e.test'])
            ->assertSessionHasErrors('email');

        foreach ([
            ['date_of_birth' => '01-01-2000'],
            ['date_of_birth' => now()->addDay()->format('Y-m-d')],
            ['identity_document_type' => 'driver_license'],
            ['public_identity_preference' => 'public_everything'],
            ['identity_issuing_country' => 'S1'],
            ['full_name' => ['array']],
        ] as $payload) {
            $field = array_key_first($payload);
            $this->actingAs(User::factory()->create())->post(route('help-applications.store'), $payload)
                ->assertSessionHasErrors($field);
        }
    }

    public function test_identity_number_is_never_flashed_or_emitted_after_validation_failure(): void
    {
        Log::spy();
        $secret = 'PRIVATE-IDENTITY-DO-NOT-EMIT';
        $response = $this->actingAs(User::factory()->create())->from(route('help-applications.create'))->post(route('help-applications.store'), [
            'identity_document_number' => $secret,
            'email' => ['invalid'],
        ]);

        $response->assertRedirect(route('help-applications.create'));
        $this->assertStringNotContainsString($secret, serialize(session()->all()));
        $this->assertStringNotContainsString($secret, $response->getContent());
        $this->assertArrayNotHasKey('identity_document_number', session()->getOldInput());
        $this->get(route('help-applications.create'))->assertDontSee($secret);
        $this->assertNoApplicationLogCalls();
    }

    public function test_update_preserves_replaces_and_clears_identity_number_and_blind_index(): void
    {
        Log::spy();
        $this->configureBlindIndex();
        $user = User::factory()->create();
        $application = HelpApplication::factory()->for($user, 'applicant')->create([
            'identity_document_type' => IdentityDocumentType::Passport,
            'identity_issuing_country' => 'SD',
            'identity_document_number' => 'ORIGINAL-123',
        ]);
        $this->seedBlindIndex($application);

        $this->actingAs($user)->patch(route('help-applications.update', $application), $this->updatePayload($application))
            ->assertSessionHasNoErrors();
        $this->assertSame('ORIGINAL-123', $application->refresh()->identity_document_number);

        $oldDigest = $application->identity_blind_index;
        $replacement = $this->updatePayload($application, ['identity_document_number' => 'REPLACED-456']);
        $replacementNumber = 'REPLACED-456';
        $response = $this->actingAs($user)->patch(route('help-applications.update', $application), $replacement);
        $response->assertSessionHasNoErrors();
        $application->refresh();
        $this->assertSame($replacementNumber, $application->identity_document_number);
        $this->assertNotSame($oldDigest, $application->identity_blind_index);

        $audit = AuditLog::query()->sole();
        $this->assertStringNotContainsString($replacementNumber, serialize($audit->getAttributes()));
        $this->assertStringNotContainsString($replacementNumber, serialize($audit->old_values));
        $this->assertStringNotContainsString($replacementNumber, serialize($audit->new_values));
        $this->assertStringNotContainsString($replacementNumber, route('help-applications.edit', $application));
        $this->assertStringNotContainsString($replacementNumber, (string) $response->headers->get('Location'));
        $this->assertStringNotContainsString($replacementNumber, $response->getContent());
        $this->assertStringNotContainsString($replacementNumber, serialize(session()->getOldInput()));
        $this->assertStringNotContainsString($replacementNumber, serialize(session()->all()));
        $this->get(route('help-applications.edit', $application))->assertDontSee($replacementNumber);
        $this->assertNoApplicationLogCalls();

        $clear = $this->updatePayload($application, ['clear_identity_document_number' => '1']);
        $this->actingAs($user)->patch(route('help-applications.update', $application), $clear)->assertSessionHasNoErrors();
        $application->refresh();
        $this->assertNull($application->identity_document_number);
        $this->assertNull($application->identity_blind_index);
        $this->assertNull($application->identity_blind_index_version);
    }

    public function test_replacement_and_clear_together_are_rejected_without_exposing_replacement(): void
    {
        $application = HelpApplication::factory()->create(['identity_document_number' => 'STORED']);
        $replacement = 'REPLACEMENT-MUST-NOT-FLASH';
        $response = $this->actingAs($application->applicant)->patch(route('help-applications.update', $application), $this->updatePayload($application, [
            'identity_document_number' => $replacement,
            'clear_identity_document_number' => '1',
        ]));

        $response->assertSessionHasErrors('identity_document_number');
        $this->assertSame('STORED', $application->refresh()->identity_document_number);
        $this->assertStringNotContainsString($replacement, serialize(session()->all()));
    }

    public function test_noop_update_preserves_timestamp_actor_and_audit_while_meaningful_update_is_safe(): void
    {
        $user = User::factory()->create();
        $originalUpdater = User::factory()->create();
        $application = HelpApplication::factory()->for($user, 'applicant')->create(['updated_by' => $originalUpdater->id]);
        $timestamp = $application->updated_at;

        $this->actingAs($user)->patch(route('help-applications.update', $application), $this->updatePayload($application))
            ->assertSessionHas('status', 'help-application-draft-unchanged');
        $application->refresh();
        $this->assertTrue($timestamp->equalTo($application->updated_at));
        $this->assertSame($originalUpdater->id, $application->updated_by);
        $this->assertSame(0, AuditLog::count());

        $this->actingAs($user)->patch(route('help-applications.update', $application), $this->updatePayload($application, ['full_name' => 'Changed']))
            ->assertSessionHas('status', 'help-application-draft-updated');
        $audit = AuditLog::query()->sole();
        $this->assertSame('help_application.draft_updated', $audit->action);
        $this->assertSame(['status' => 'draft'], $audit->old_values);
        $this->assertSame(['status' => 'draft'], $audit->new_values);
    }

    public function test_audit_failure_rolls_back_creation_and_update(): void
    {
        $this->mock(AuditLogger::class, function (MockInterface $mock): void {
            $mock->shouldReceive('log')->andThrow(new RuntimeException('Synthetic audit failure'));
        });
        $user = User::factory()->create();

        try {
            $this->actingAs($user)->post(route('help-applications.store'), ['full_name' => 'Rollback']);
        } catch (RuntimeException) {
            // Expected from the deliberately failing collaborator.
        }
        $this->assertSame(0, HelpApplication::count());

        $application = HelpApplication::factory()->for($user, 'applicant')->create(['full_name' => 'Original']);
        try {
            $this->actingAs($user)->patch(route('help-applications.update', $application), $this->updatePayload($application, ['full_name' => 'Changed']));
        } catch (RuntimeException) {
            // Expected from the deliberately failing collaborator.
        }
        $this->assertSame('Original', $application->refresh()->full_name);
    }

    public function test_missing_blind_index_configuration_rolls_back_complete_identity_change(): void
    {
        config()->set('identity.blind_index.current_version', null);
        config()->set('identity.blind_index.keys', []);
        $application = HelpApplication::factory()->create(['identity_document_number' => null]);

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($application->applicant)->patch(route('help-applications.update', $application), $this->updatePayload($application, [
                'identity_document_type' => 'passport',
                'identity_issuing_country' => 'SD',
                'identity_document_number' => 'PRIVATE-ROLLBACK',
                'full_name' => 'Must Roll Back',
            ]));
            $this->fail('Invalid blind-index configuration must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Identity blind-index configuration is invalid.', $exception->getMessage());
        }

        $application->refresh();
        $this->assertNotSame('Must Roll Back', $application->full_name);
        $this->assertNull($application->identity_document_number);
    }

    public function test_landing_states_navigation_and_edit_html_are_private(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('help-applications.index'))
            ->assertSeeText('Start Help Application')
            ->assertSeeText('My Help Application');

        $application = HelpApplication::factory()->for($user, 'applicant')->create(['identity_document_number' => 'NEVER-IN-HTML']);
        $this->actingAs($user)->get(route('help-applications.index'))->assertSeeText('Continue Draft');
        $this->actingAs($user)->get(route('help-applications.edit', $application))
            ->assertOk()
            ->assertSeeText('An identity number is stored securely.')
            ->assertDontSee('NEVER-IN-HTML')
            ->assertSee('autocomplete="off"', false);

        $application->status = HelpApplicationStatus::UnderReview;
        $application->save();
        $this->actingAs($user)->get(route('help-applications.index'))
            ->assertSeeText('Under review')
            ->assertDontSee('NEVER-IN-HTML');

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('dashboard'))->assertDontSee('My Help Application');
    }

    public function test_navigation_uses_the_complete_eligible_applicant_policy(): void
    {
        $eligible = User::factory()->create();
        $this->actingAs($eligible);
        $this->assertStringContainsString('My Help Application', view('dashboard')->render());

        foreach ([
            User::factory()->admin()->create(),
            User::factory()->superAdmin()->create(),
            User::factory()->disabled()->create(),
            User::factory()->mustChangePassword()->create(),
        ] as $ineligible) {
            $this->actingAs($ineligible);
            $this->assertStringNotContainsString('My Help Application', view('dashboard')->render());
        }
    }

    public function test_public_homepage_sidebar_exposes_only_the_policy_authorized_private_index_link(): void
    {
        $indexUrl = route('help-applications.index');

        $guestResponse = $this->get('/');
        $guestResponse->assertOk()->assertDontSee($indexUrl, false)->assertDontSee('My Help Application');

        $eligible = User::factory()->create(['id' => 987654321]);
        $application = HelpApplication::factory()->for($eligible, 'applicant')->create([
            'reference' => '12345678-1234-4234-8234-123456789abc',
            'full_name' => 'PRIVATE HOMEPAGE APPLICATION VALUE',
        ]);

        $response = $this->actingAs($eligible)->get('/');
        $response->assertOk()
            ->assertSee('href="'.$indexUrl.'"', false)
            ->assertSee('data-en="My Help Application"', false)
            ->assertSee('data-ar="طلب المساعدة الخاص بي"', false)
            ->assertDontSee('Submit a Request')
            ->assertDontSee('data-en="Submit a Request"', false)
            ->assertSee('href="#donationCases"', false)
            ->assertSee('data-en="Donation Cases"', false)
            ->assertSee('href="'.route('profile.edit').'"', false)
            ->assertSee('action="'.route('logout').'"', false)
            ->assertSee('data-en="Profile"', false)
            ->assertSee('data-en="Logout"', false);

        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, 'href="'.$indexUrl.'"'));
        $this->assertSame(1, substr_count($html, 'data-en="My Help Application"'));
        $this->assertSame(0, substr_count($html, 'href="#"><i class="fas fa-paper-plane"'));

        preg_match('/<a href="'.preg_quote($indexUrl, '/').'">(?<link>.*?)<\/a>/s', $html, $matches);
        $this->assertArrayHasKey('link', $matches);
        $this->assertStringNotContainsString($application->reference, $matches[0]);
        $this->assertStringNotContainsString((string) $application->applicant_id, $matches[0]);
        $this->assertStringNotContainsString('PRIVATE HOMEPAGE APPLICATION VALUE', $matches[0]);

        foreach ([
            User::factory()->admin()->create(),
            User::factory()->superAdmin()->create(),
            User::factory()->disabled()->create(),
            User::factory()->mustChangePassword()->create(),
        ] as $ineligible) {
            $this->actingAs($ineligible);
            $ineligibleHtml = view('welcome')->render();
            $this->assertSame(0, substr_count($ineligibleHtml, 'href="'.$indexUrl.'"'));
            $this->assertStringNotContainsString('data-en="My Help Application"', $ineligibleHtml);
        }
    }

    public function test_error_summary_links_only_to_real_allowlisted_form_controls(): void
    {
        $user = User::factory()->create();

        $fieldResponse = $this->actingAs($user)->withSession([
            'errors' => $this->errorBag(['email' => 'Field message / رسالة الحقل']),
        ])->get(route('help-applications.create'));
        $fieldResponse->assertOk()->assertSee('role="alert"', false)->assertSee('href="#email"', false);
        $this->assertSame(1, substr_count($fieldResponse->getContent(), 'href="#email"'));
        $this->assertSame(1, substr_count($fieldResponse->getContent(), 'id="email"'));

        $serviceResponse = $this->actingAs($user)->withSession([
            'errors' => $this->errorBag(['help_application' => 'Service message / رسالة الخدمة']),
        ])->get(route('help-applications.create'));
        $serviceResponse->assertOk()->assertSeeText('Service message / رسالة الخدمة')->assertDontSee('href="#help_application"', false);

        $unknownResponse = $this->actingAs($user)->withSession([
            'errors' => $this->errorBag(['unexpected_error' => 'Unknown message / رسالة غير معروفة']),
        ])->get(route('help-applications.create'));
        $unknownResponse->assertOk()->assertSeeText('Unknown message / رسالة غير معروفة')->assertDontSee('href="#unexpected_error"', false);

        preg_match_all('/href="#([A-Za-z0-9_-]+)"/', $fieldResponse->getContent(), $matches);
        foreach ($matches[1] as $target) {
            $this->assertSame(1, substr_count($fieldResponse->getContent(), 'id="'.$target.'"'));
        }
    }

    private function configureBlindIndex(): void
    {
        config()->set('identity.blind_index.current_version', 2);
        config()->set('identity.blind_index.keys', [2 => base64_encode(str_repeat('draft-test-key-', 3))]);
    }

    private function assertNoApplicationLogCalls(): void
    {
        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'] as $method) {
            Log::shouldNotHaveReceived($method);
        }
    }

    /** @param array<string, string> $messages */
    private function errorBag(array $messages): ViewErrorBag
    {
        return (new ViewErrorBag)->put('default', new MessageBag($messages));
    }

    private function seedBlindIndex(HelpApplication $application): void
    {
        $service = app(IdentityBlindIndex::class);
        $application->identity_blind_index_version = 2;
        $application->identity_blind_index = $service->compute($application->identity_document_type, $application->identity_issuing_country, $application->identity_document_number, 2);
        $application->save();
    }

    private function completePayload(): array
    {
        return [
            'full_name' => ' Applicant Name ', 'email' => 'applicant@example.test', 'phone' => '+249000000000',
            'address' => 'Private address', 'date_of_birth' => '1990-01-02',
            'identity_document_type' => 'passport', 'identity_issuing_country' => ' sd ',
            'identity_document_number' => 'PRIVATE-123', 'requested_amount' => '123.4',
            'private_story' => 'Private story', 'preferred_receiving_method' => 'General cash preference',
            'public_identity_preference' => 'anonymous',
        ];
    }

    private function updatePayload(HelpApplication $application, array $overrides = []): array
    {
        $payload = [];
        foreach (['full_name', 'email', 'phone', 'address', 'date_of_birth', 'identity_issuing_country', 'requested_amount', 'private_story', 'preferred_receiving_method'] as $field) {
            $payload[$field] = $application->{$field};
        }
        $payload['identity_document_type'] = $application->identity_document_type?->value;
        $payload['public_identity_preference'] = $application->public_identity_preference?->value;

        return array_merge($payload, $overrides);
    }
}
