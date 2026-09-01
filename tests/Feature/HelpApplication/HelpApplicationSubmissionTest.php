<?php

namespace Tests\Feature\HelpApplication;

use App\Enums\HelpApplicationStatus;
use App\Enums\IdentityDocumentType;
use App\Models\AuditLog;
use App\Models\HelpApplication;
use App\Models\HelpApplicationDocument;
use App\Models\InternalNotification;
use App\Models\InternalNotificationEvent;
use App\Models\InternalNotificationEventRecipient;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\HelpApplicationDocumentPath;
use App\Services\HelpApplicationSubmissionService;
use App\Services\IdentityBlindIndex;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class HelpApplicationSubmissionTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh');
        $this->app[Kernel::class]->setArtisan(null);
    }

    public function test_route_is_the_single_bounded_uuid_post_surface(): void
    {
        $routes = collect(app('router')->getRoutes())->filter(fn ($route): bool => $route->getName() === 'help-applications.submit'
            && $route->uri() === 'help-applications/{helpApplication}/submit'
            && $route->getActionName() === 'App\\Http\\Controllers\\Applicant\\HelpApplicationController@submit'
            && $route->methods() === ['POST']);
        $this->assertCount(1, $routes);
        $route = $routes->sole();

        $this->assertSame(['POST'], $route->methods());
        $this->assertSame('help-applications/{helpApplication}/submit', $route->uri());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('role:user', $route->gatherMiddleware());
        $this->assertContains('throttle:6,1', $route->gatherMiddleware());
        $this->post('/help-applications/123/submit', ['consent' => '1'])->assertRedirect(route('login'));

        $owner = User::factory()->user()->create();
        $this->actingAs($owner)->post('/help-applications/123/submit', ['consent' => '1'])->assertNotFound();
    }

    public function test_complete_owner_submission_transitions_once_and_creates_private_foundation(): void
    {
        Storage::fake('help_application_documents');
        $applicant = User::factory()->user()->create();
        $administrator = User::factory()->admin()->create();
        $application = $this->readyApplication($applicant);
        $originalUpdatedAt = $application->updated_at;

        $response = $this->actingAs($applicant)->post(route('help-applications.submit', $application), [
            'consent' => '1',
            'category_id' => 999,
            'status' => 'approved',
        ]);

        $response->assertRedirect(route('help-applications.index'));
        $application->refresh();
        $this->assertSame(HelpApplicationStatus::Pending, $application->status);
        $this->assertTrue($application->open_slot);
        $this->assertNull($application->category_id);
        $this->assertSame($applicant->id, $application->updated_by);
        $this->assertSame(HelpApplication::CONSENT_VERSION, $application->consent_version);
        $this->assertTrue($application->submitted_at->equalTo($application->status_changed_at));
        $this->assertTrue($application->submitted_at->equalTo($application->consented_at));
        $this->assertTrue($application->updated_at->greaterThanOrEqualTo($originalUpdatedAt));

        $audit = AuditLog::query()->where('action', 'help_application.submitted')->sole();
        $this->assertSame(['status' => 'draft', 'open_slot' => true], $audit->old_values);
        $this->assertSame(['status' => 'pending', 'open_slot' => true], $audit->new_values);
        $this->assertSame(1, InternalNotificationEvent::query()->count());
        $this->assertSame(2, InternalNotificationEventRecipient::query()->count());
        $this->assertSame(2, InternalNotification::query()->count());
        $this->assertDatabaseHas('internal_notification_event_recipients', ['recipient_id' => $administrator->id, 'audience' => 'administrator']);

        $timestamps = [$application->submitted_at, $application->status_changed_at, $application->consented_at, $application->updated_at];
        $event = InternalNotificationEvent::query()->sole();
        $eventProjectedAt = $event->projected_at;
        $intentProjectionState = InternalNotificationEventRecipient::query()->orderBy('id')->get()
            ->map(fn (InternalNotificationEventRecipient $intent): array => [
                $intent->state->value,
                $intent->attempts,
                $intent->last_attempted_at?->format('Y-m-d H:i:s'),
                $intent->projected_at?->format('Y-m-d H:i:s'),
            ])->all();
        $this->actingAs($applicant)->post(route('help-applications.submit', $application), ['consent' => '1'])
            ->assertRedirect(route('help-applications.index'))
            ->assertSessionHas('status', 'help-application-already-submitted');
        $this->actingAs($applicant)->post(route('help-applications.submit', $application), [])
            ->assertRedirect(route('help-applications.index'))
            ->assertSessionHas('status', 'help-application-already-submitted');
        $application->refresh();
        $this->assertTrue($application->submitted_at->equalTo($timestamps[0]));
        $this->assertTrue($application->status_changed_at->equalTo($timestamps[1]));
        $this->assertTrue($application->consented_at->equalTo($timestamps[2]));
        $this->assertTrue($application->updated_at->equalTo($timestamps[3]));
        $this->assertSame(HelpApplication::CONSENT_VERSION, $application->consent_version);
        $this->assertSame($applicant->id, $application->updated_by);
        $this->assertSame(1, AuditLog::query()->where('action', 'help_application.submitted')->count());
        $this->assertDatabaseCount('help_application_duplicate_warnings', 0);
        $this->assertSame(1, InternalNotificationEvent::query()->count());
        $this->assertSame(2, InternalNotificationEventRecipient::query()->count());
        $this->assertSame(2, InternalNotification::query()->count());
        $this->assertTrue(InternalNotificationEvent::query()->sole()->projected_at->equalTo($eventProjectedAt));
        $this->assertSame($intentProjectionState, InternalNotificationEventRecipient::query()->orderBy('id')->get()
            ->map(fn (InternalNotificationEventRecipient $intent): array => [
                $intent->state->value,
                $intent->attempts,
                $intent->last_attempted_at?->format('Y-m-d H:i:s'),
                $intent->projected_at?->format('Y-m-d H:i:s'),
            ])->all());
    }

    public function test_consent_and_private_document_bytes_are_required_and_foreign_owner_is_hidden(): void
    {
        Storage::fake('help_application_documents');
        $applicant = User::factory()->user()->create();
        $application = $this->readyApplication($applicant, storeBytes: false);

        $this->actingAs($applicant)->post(route('help-applications.submit', $application), [])
            ->assertSessionHasErrors('consent');
        $this->actingAs($applicant)->post(route('help-applications.submit', $application), ['consent' => '0'])
            ->assertSessionHasErrors('consent');
        $this->actingAs($applicant)->post(route('help-applications.submit', $application), ['consent' => '1'])
            ->assertSessionHasErrors('application');
        $this->assertSame(HelpApplicationStatus::Draft, $application->refresh()->status);

        $foreign = User::factory()->user()->create();
        $this->actingAs($foreign)->post(route('help-applications.submit', $application), ['consent' => '1'])->assertNotFound();
    }

    public function test_ineligible_accounts_are_rejected_before_any_submission_side_effect(): void
    {
        Storage::fake('help_application_documents');
        $cases = [
            [User::factory()->admin()->create(), 403, null],
            [User::factory()->superAdmin()->create(), 403, null],
            [User::factory()->user()->disabled()->create(), 302, route('login')],
            [User::factory()->user()->mustChangePassword()->create(), 302, route('password.change.required.edit')],
        ];

        foreach ($cases as [$actor, $status, $location]) {
            $application = HelpApplication::factory()->create(['applicant_id' => $actor->id]);
            $response = $this->actingAs($actor)->post(route('help-applications.submit', $application), ['consent' => '1']);
            $response->assertStatus($status);
            if ($location !== null) {
                $response->assertRedirect($location);
            }
            $this->assertSame(HelpApplicationStatus::Draft, $application->refresh()->status);
        }

        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('help_application_duplicate_warnings', 0);
        $this->assertDatabaseCount('internal_notification_events', 0);
        $this->assertDatabaseCount('internal_notification_event_recipients', 0);
        $this->assertDatabaseCount('internal_notifications', 0);
    }

    public function test_duplicate_warnings_and_frozen_notification_recipients_are_exact_and_private(): void
    {
        Storage::fake('help_application_documents');
        $applicant = User::factory()->user()->create();
        $eligibleAdmin = User::factory()->admin()->create();
        $eligibleSuperAdmin = User::factory()->superAdmin()->create();
        User::factory()->user()->create();
        User::factory()->admin()->disabled()->create();
        User::factory()->superAdmin()->mustChangePassword()->create();
        $application = $this->readyApplication($applicant);

        $identity = $application->identity_document_number;
        $blindIndex = app(IdentityBlindIndex::class);
        $version = $blindIndex->currentKeyVersion();
        $matches = HelpApplication::factory()->count(2)->closed()->create([
            'identity_document_type' => IdentityDocumentType::NationalId,
            'identity_issuing_country' => 'SD',
            'identity_blind_index_version' => $version,
            'identity_blind_index' => $blindIndex->compute(IdentityDocumentType::NationalId, 'SD', $identity, $version),
        ]);
        DB::table('help_applications')->whereIn('id', $matches->pluck('id'))->update([
            'identity_document_number' => 'deliberately-invalid-encrypted-value',
        ]);

        $this->actingAs($applicant)->post(route('help-applications.submit', $application), ['consent' => '1'])
            ->assertRedirect(route('help-applications.index'));
        $this->assertSame(HelpApplicationStatus::Pending, $application->refresh()->status);

        $warnings = DB::table('help_application_duplicate_warnings')->orderBy('matched_application_id')->get();
        $this->assertSame($matches->pluck('id')->sort()->values()->all(), $warnings->pluck('matched_application_id')->all());
        $this->assertTrue($warnings->every(fn ($warning): bool => $warning->submitted_application_id === $application->id));
        $this->assertFalse($warnings->contains(fn ($warning): bool => $warning->matched_application_id === $application->id));

        $event = InternalNotificationEvent::query()->sole();
        $this->assertSame(hash('sha256', implode("\0", ['internal_notification', 'help_application_submitted', (string) $application->id])), $event->deduplication_key);
        $this->assertSame([$applicant->id, $eligibleAdmin->id, $eligibleSuperAdmin->id], $event->recipientIntents()->orderBy('recipient_id')->pluck('recipient_id')->all());
        $this->assertDatabaseHas('internal_notification_event_recipients', ['recipient_id' => $applicant->id, 'recipient_role' => 'user', 'audience' => 'applicant', 'notification_type' => 'help_application_submission_confirmation']);
        foreach ([$eligibleAdmin, $eligibleSuperAdmin] as $administrator) {
            $this->assertDatabaseHas('internal_notification_event_recipients', ['recipient_id' => $administrator->id, 'recipient_role' => $administrator->role->value, 'audience' => 'administrator', 'notification_type' => 'help_application_new_submission']);
        }
        $this->assertSame(3, InternalNotification::query()->count());
        $this->assertTrue(InternalNotification::query()->get()->every(fn ($notification): bool => $notification->allowlistedData() === ['application_reference' => $application->reference, 'status' => 'pending']));

        $serializedAudit = AuditLog::query()->where('action', 'help_application.submitted')->sole()->toArray();
        $this->assertSame(['status', 'open_slot'], array_keys($serializedAudit['old_values']));
        $this->assertSame(['status', 'open_slot'], array_keys($serializedAudit['new_values']));
    }

    public function test_only_the_exact_string_one_is_valid_consent_for_a_draft(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('help_application_documents');
        $applicant = User::factory()->user()->create();
        $application = $this->readyApplication($applicant);

        foreach ([null, false, true, 0, '0', 'true', 'yes', 'on', [], ['1'], 1] as $ambiguous) {
            $response = $this->actingAs($applicant)->post(route('help-applications.submit', $application), ['consent' => $ambiguous]);
            $response->assertSessionHasErrors('consent');
            $this->assertSame(HelpApplicationStatus::Draft, $application->refresh()->status);
            $this->assertDatabaseCount('internal_notification_events', 0);
        }

        $this->actingAs($applicant)->post(route('help-applications.submit', $application), ['consent' => '1'])
            ->assertRedirect(route('help-applications.index'));
        $this->assertSame(HelpApplicationStatus::Pending, $application->refresh()->status);
    }

    public function test_direct_service_submission_requires_consent_but_pending_noop_does_not(): void
    {
        Storage::fake('help_application_documents');
        $applicant = User::factory()->user()->create();
        $application = $this->readyApplication($applicant);
        $request = Request::create('/private-submit-test', 'POST');

        try {
            app(HelpApplicationSubmissionService::class)->submit($applicant, $application, false, $request);
            $this->fail('A draft was submitted without affirmative consent.');
        } catch (ValidationException $exception) {
            $this->assertSame(['consent'], array_keys($exception->errors()));
        }

        $this->assertSame(HelpApplicationStatus::Draft, $application->refresh()->status);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('help_application_duplicate_warnings', 0);
        $this->assertDatabaseCount('internal_notification_events', 0);

        $this->assertTrue(app(HelpApplicationSubmissionService::class)->submit($applicant, $application, true, $request));
        $timestamps = [$application->refresh()->submitted_at, $application->consented_at, $application->updated_at];
        $this->assertFalse(app(HelpApplicationSubmissionService::class)->submit($applicant, $application, false, $request));
        $application->refresh();
        $this->assertTrue($application->submitted_at->equalTo($timestamps[0]));
        $this->assertTrue($application->consented_at->equalTo($timestamps[1]));
        $this->assertTrue($application->updated_at->equalTo($timestamps[2]));
        $this->assertDatabaseCount('internal_notification_events', 1);
    }

    public function test_every_required_stored_field_is_rechecked_at_submission(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('help_application_documents');
        $fields = [
            'full_name', 'email', 'phone', 'address', 'date_of_birth', 'identity_document_type',
            'identity_issuing_country', 'identity_document_number', 'identity_blind_index',
            'identity_blind_index_version', 'requested_amount', 'private_story',
            'preferred_receiving_method', 'public_identity_preference',
        ];

        foreach ($fields as $field) {
            $applicant = User::factory()->user()->create();
            $application = $this->readyApplication($applicant);
            DB::table('help_applications')->where('id', $application->id)->update([$field => null]);

            $expectedError = in_array($field, ['identity_blind_index', 'identity_blind_index_version'], true)
                ? 'application'
                : $field;
            $this->actingAs($applicant)->post(route('help-applications.submit', $application), ['consent' => '1'])
                ->assertSessionHasErrors($expectedError);
            $this->assertDatabaseHas('help_applications', ['id' => $application->id, 'status' => 'draft']);
            $this->assertDatabaseCount('internal_notification_events', 0);
        }
    }

    public function test_multiple_correctable_submission_errors_link_to_and_highlight_exact_real_controls(): void
    {
        Storage::fake('help_application_documents');
        $applicant = User::factory()->user()->create();
        $application = HelpApplication::factory()->create(['applicant_id' => $applicant->id]);
        $invalidFields = [
            'full_name', 'phone', 'address', 'date_of_birth', 'identity_document_type',
            'identity_issuing_country', 'identity_document_number', 'requested_amount',
            'private_story', 'preferred_receiving_method', 'public_identity_preference',
        ];
        DB::table('help_applications')->where('id', $application->id)->update(array_fill_keys([
            ...$invalidFields,
            'identity_blind_index',
            'identity_blind_index_version',
        ], null));

        $response = $this->actingAs($applicant)->from(route('help-applications.edit', $application))
            ->post(route('help-applications.submit', $application), ['consent' => '1']);
        $response->assertRedirect(route('help-applications.edit', $application))
            ->assertSessionHasErrors([...$invalidFields, 'document'])
            ->assertSessionDoesntHaveErrors(['email', 'identity_blind_index', 'identity_blind_index_version']);

        $session = json_encode(session()->all(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('identity_blind_index', $session);
        $this->assertStringNotContainsString('identity_blind_index_version', $session);
        $this->assertStringNotContainsString('identity_document_number', json_encode(session()->getOldInput(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('document', json_encode(session()->getOldInput(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('purpose', json_encode(session()->getOldInput(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('consent', json_encode(session()->getOldInput(), JSON_THROW_ON_ERROR));

        $page = $this->get(route('help-applications.edit', $application));
        $page->assertOk()
            ->assertSee('Full name is required before submission. / يجب إدخال الاسم الكامل قبل إرسال الطلب.')
            ->assertSee('At least one eligible private supporting document is required before submission. / يجب رفع مستند داعم خاص مؤهل واحد على الأقل قبل إرسال الطلب.')
            ->assertDontSee('identity_blind_index')
            ->assertDontSee('identity_blind_index_version');
        $html = $page->getContent();

        foreach ([...$invalidFields, 'document'] as $field) {
            $this->assertSame(1, substr_count($html, 'href="#'.$field.'"'));
            $this->assertSame(1, preg_match('~<(?:input|textarea|select)\b[^>]*\bid="'.preg_quote($field, '~').'"[^>]*>~s', $html, $control));
            $this->assertStringContainsString('aria-invalid="true"', $control[0]);
            $this->assertStringContainsString($field.'-error', $control[0]);
            $this->assertStringContainsString('border-red-200', $control[0]);
            $this->assertSame(1, substr_count($html, 'id="'.$field.'-error"'));
        }

        $this->assertSame(1, preg_match('~<input\b[^>]*\bid="email"[^>]*>~s', $html, $emailControl));
        $this->assertStringNotContainsString('aria-invalid="true"', $emailControl[0]);
        $this->assertStringNotContainsString('border-red-200', $emailControl[0]);
        $this->assertSame('', (string) preg_replace('~.*<input id="identity_document_number" name="identity_document_number" value="([^"]*)".*~s', '$1', $html));
        $this->assertSame(HelpApplicationStatus::Draft, $application->refresh()->status);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('help_application_duplicate_warnings', 0);
        $this->assertDatabaseCount('internal_notification_events', 0);
        $this->assertDatabaseCount('internal_notification_event_recipients', 0);
        $this->assertDatabaseCount('internal_notifications', 0);
    }

    public function test_consent_error_links_to_the_unchecked_red_control_without_old_input(): void
    {
        Storage::fake('help_application_documents');
        $applicant = User::factory()->user()->create();
        $application = $this->readyApplication($applicant);

        $this->actingAs($applicant)->from(route('help-applications.edit', $application))
            ->post(route('help-applications.submit', $application), ['consent' => 'on'])
            ->assertRedirect(route('help-applications.edit', $application))
            ->assertSessionHasErrors('consent');
        $this->assertArrayNotHasKey('consent', session()->getOldInput());

        $page = $this->get(route('help-applications.edit', $application));
        $html = $page->getContent();
        $this->assertSame(1, substr_count($html, 'href="#consent"'));
        $this->assertSame(1, preg_match('~<input\b[^>]*\bid="consent"[^>]*>~s', $html, $control));
        $this->assertStringContainsString('aria-invalid="true"', $control[0]);
        $this->assertStringContainsString('consent-error', $control[0]);
        $this->assertStringContainsString('border-red-200', $control[0]);
        $this->assertStringNotContainsString('checked', $control[0]);
        $this->assertSame(HelpApplicationStatus::Draft, $application->refresh()->status);
    }

    public function test_internal_document_integrity_error_remains_generic_and_unlinked(): void
    {
        Storage::fake('help_application_documents');
        $applicant = User::factory()->user()->create();
        $application = $this->readyApplication($applicant);
        DB::table('help_application_documents')->where('help_application_id', $application->id)
            ->update(['checksum' => 'malformed-internal-value']);

        $this->actingAs($applicant)->from(route('help-applications.edit', $application))
            ->post(route('help-applications.submit', $application), ['consent' => '1'])
            ->assertRedirect(route('help-applications.edit', $application))
            ->assertSessionHasErrors('application')
            ->assertSessionDoesntHaveErrors('document');

        $page = $this->get(route('help-applications.edit', $application));
        $page->assertOk()
            ->assertSee('This Help Application is not ready to submit.')
            ->assertDontSee('malformed-internal-value')
            ->assertDontSee('checksum');
        $html = $page->getContent();
        $this->assertStringNotContainsString('href="#application"', $html);
        $this->assertSame(1, preg_match('~<input\b[^>]*\bid="document"[^>]*>~s', $html, $control));
        $this->assertStringNotContainsString('aria-invalid="true"', $control[0]);
        $this->assertStringNotContainsString('border-red-200', $control[0]);
        $this->assertSame(HelpApplicationStatus::Draft, $application->refresh()->status);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('internal_notification_events', 0);
    }

    public function test_identity_digest_version_and_historical_configuration_fail_closed(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('help_application_documents');

        foreach ([
            ['identity_blind_index' => str_repeat('a', 64)],
            ['identity_blind_index_version' => 2],
        ] as $change) {
            $applicant = User::factory()->user()->create();
            $application = $this->readyApplication($applicant);
            DB::table('help_applications')->where('id', $application->id)->update($change);
            $this->actingAs($applicant)->post(route('help-applications.submit', $application), ['consent' => '1'])
                ->assertSessionHasErrors('application');
            $this->assertDatabaseHas('help_applications', ['id' => $application->id, 'status' => 'draft']);
        }

        $applicant = User::factory()->user()->create();
        $application = $this->readyApplication($applicant);
        HelpApplication::factory()->closed()->create(['identity_blind_index_version' => 99, 'identity_blind_index' => str_repeat('b', 64)]);
        $this->actingAs($applicant)->post(route('help-applications.submit', $application), ['consent' => '1'])
            ->assertSessionHasErrors('application');
        $this->assertSame(HelpApplicationStatus::Draft, $application->refresh()->status);
    }

    public function test_identity_key_rotation_matches_one_prior_application_without_decrypting_it(): void
    {
        Storage::fake('help_application_documents');
        $original = config('identity.blind_index');
        $rotatedSecret = base64_encode(str_repeat('r', 32));

        try {
            config()->set('identity.blind_index.keys.2', $rotatedSecret);
            $applicant = User::factory()->user()->create();
            $application = $this->readyApplication($applicant);
            $blindIndex = app(IdentityBlindIndex::class);
            $prior = HelpApplication::factory()->closed()->create([
                'identity_document_type' => IdentityDocumentType::NationalId,
                'identity_issuing_country' => 'SD',
                'identity_blind_index_version' => 2,
                'identity_blind_index' => $blindIndex->compute(IdentityDocumentType::NationalId, 'SD', $application->identity_document_number, 2),
            ]);
            DB::table('help_applications')->where('id', $prior->id)->update([
                'identity_document_number' => 'deliberately-invalid-encrypted-value',
            ]);

            $this->actingAs($applicant)->post(route('help-applications.submit', $application), ['consent' => '1'])
                ->assertRedirect(route('help-applications.index'));
            $this->assertSame(HelpApplicationStatus::Pending, $application->refresh()->status);
            $this->assertDatabaseHas('help_application_duplicate_warnings', [
                'submitted_application_id' => $application->id,
                'matched_application_id' => $prior->id,
            ]);
        } finally {
            config()->set('identity.blind_index', $original);
        }
    }

    public function test_malformed_current_identity_and_document_eligibility_configuration_fail_closed(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('help_application_documents');
        $originalIdentity = config('identity.blind_index');
        $originalStatuses = config('help_application_documents.submission_eligible_security_statuses');

        try {
            $applicant = User::factory()->user()->create();
            $application = $this->readyApplication($applicant);
            config()->set('identity.blind_index.current_version', 'invalid');
            $this->actingAs($applicant)->post(route('help-applications.submit', $application), ['consent' => '1'])
                ->assertSessionHasErrors('application');
            $this->assertSame(HelpApplicationStatus::Draft, $application->refresh()->status);
            config()->set('identity.blind_index', $originalIdentity);

            foreach ([['accepted_unscanned' => true], ['accepted_unscanned', 7], ['unknown']] as $unsafe) {
                $applicant = User::factory()->user()->create();
                $application = $this->readyApplication($applicant);
                config()->set('help_application_documents.submission_eligible_security_statuses', $unsafe);
                $this->actingAs($applicant)->post(route('help-applications.submit', $application), ['consent' => '1'])
                    ->assertSessionHasErrors('application');
                $this->assertSame(HelpApplicationStatus::Draft, $application->refresh()->status);
            }
        } finally {
            config()->set('identity.blind_index', $originalIdentity);
            config()->set('help_application_documents.submission_eligible_security_statuses', $originalStatuses);
        }
    }

    public function test_document_metadata_paths_and_bytes_fail_closed_while_a_later_valid_candidate_succeeds(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('help_application_documents');
        $cases = [
            'removed' => ['removed_at' => now()],
            'disallowed' => ['security_status' => 'rejected'],
            'no-purpose' => ['purpose' => null],
            'unknown-purpose' => ['purpose' => 'unknown'],
            'unsafe-path' => ['storage_path' => '../public/document.pdf'],
            'bad-checksum' => ['checksum' => 'invalid'],
        ];

        foreach ($cases as $case => $change) {
            $applicant = User::factory()->user()->create();
            $application = $this->readyApplication($applicant);
            DB::table('help_application_documents')->where('help_application_id', $application->id)->update($change);
            $expectedError = in_array($case, ['unknown-purpose', 'unsafe-path', 'bad-checksum'], true) ? 'application' : 'document';
            $this->actingAs($applicant)->post(route('help-applications.submit', $application), ['consent' => '1'])
                ->assertSessionHasErrors($expectedError);
            $this->assertSame(HelpApplicationStatus::Draft, $application->refresh()->status);
        }

        foreach (['', 'short', str_repeat('x', strlen('private verified supporting bytes')), 'private verified supporting bytes plus extra'] as $wrongBytes) {
            $applicant = User::factory()->user()->create();
            $application = $this->readyApplication($applicant);
            $document = $application->documents()->sole();
            Storage::disk('help_application_documents')->put($document->storage_path, $wrongBytes);
            $this->actingAs($applicant)->post(route('help-applications.submit', $application), ['consent' => '1'])
                ->assertSessionHasErrors('application');
        }

        $applicant = User::factory()->user()->create();
        $application = $this->readyApplication($applicant);
        DB::table('help_application_documents')->where('help_application_id', $application->id)->update(['storage_path' => 'public/unsafe.pdf']);
        $this->addValidDocument($application);
        $this->actingAs($applicant)->post(route('help-applications.submit', $application), ['consent' => '1'])
            ->assertRedirect(route('help-applications.index'));
        $this->assertSame(HelpApplicationStatus::Pending, $application->refresh()->status);
    }

    public function test_audit_failure_rolls_back_transition_warnings_event_intents_and_projection(): void
    {
        Storage::fake('help_application_documents');
        $applicant = User::factory()->user()->create();
        $application = $this->readyApplication($applicant);
        HelpApplication::factory()->closed()->create([
            'identity_blind_index_version' => $application->identity_blind_index_version,
            'identity_blind_index' => $application->identity_blind_index,
        ]);
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('log')->once()->andThrow(new RuntimeException('controlled audit failure'));
        $this->app->instance(AuditLogger::class, $audit);

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($applicant)->post(route('help-applications.submit', $application), ['consent' => '1']);
            $this->fail('Audit failure did not abort submission.');
        } catch (RuntimeException $exception) {
            $this->assertSame('controlled audit failure', $exception->getMessage());
        }

        $application->refresh();
        $this->assertSame(HelpApplicationStatus::Draft, $application->status);
        $this->assertNull($application->submitted_at);
        $this->assertNull($application->consented_at);
        $this->assertNull($application->consent_version);
        $this->assertDatabaseCount('help_application_duplicate_warnings', 0);
        $this->assertDatabaseCount('internal_notification_events', 0);
        $this->assertDatabaseCount('internal_notification_event_recipients', 0);
        $this->assertDatabaseCount('internal_notifications', 0);
    }

    public function test_projector_reported_failure_is_recoverable_and_generic_logging_failure_does_not_mask_success(): void
    {
        Storage::fake('help_application_documents');
        $applicant = User::factory()->user()->create();
        $application = $this->readyApplication($applicant);
        $sabotaged = false;
        DB::listen(function ($query) use ($application, &$sabotaged): void {
            if (! $sabotaged && str_contains($query->sql, 'insert into "internal_notification_event_recipients"')) {
                $sabotaged = true;
                DB::table('help_applications')->where('id', $application->id)->update(['reference' => 'malformed-private-reference']);
            }
        });
        Log::shouldReceive('warning')->once()->with('Internal notification projection failed.');
        Log::shouldReceive('warning')->once()->with('Help Application notification projection could not be completed.')
            ->andThrow(new RuntimeException('controlled logging failure'));

        $this->actingAs($applicant)->post(route('help-applications.submit', $application), ['consent' => '1'])
            ->assertRedirect(route('help-applications.index'));

        $this->assertTrue($sabotaged);
        $this->assertSame(HelpApplicationStatus::Pending, $application->refresh()->status);
        $this->assertDatabaseCount('internal_notification_events', 1);
        $this->assertDatabaseHas('internal_notification_event_recipients', ['state' => 'pending', 'attempts' => 1]);
        $this->assertDatabaseCount('internal_notifications', 0);
    }

    public function test_pending_application_exposes_no_mutation_controls_and_direct_mutations_fail(): void
    {
        Storage::fake('help_application_documents');
        $applicant = User::factory()->user()->create();
        $application = $this->readyApplication($applicant);
        $document = $application->documents()->sole();
        $this->actingAs($applicant)->post(route('help-applications.submit', $application), ['consent' => '1']);

        $index = $this->actingAs($applicant)->get(route('help-applications.index'));
        $index->assertOk()->assertSee('Pending')->assertDontSee('name="consent"', false)
            ->assertDontSee(route('help-applications.update', $application), false)
            ->assertDontSee(route('help-applications.documents.store', $application), false)
            ->assertDontSee(route('help-applications.submit', $application), false);
        $this->get(route('help-applications.edit', $application))->assertForbidden();
        $this->patch(route('help-applications.update', $application), ['full_name' => 'Changed'])->assertForbidden();
        $this->post(route('help-applications.documents.store', $application), [])->assertForbidden();
        $this->delete(route('help-applications.documents.destroy', [$application, $document]))->assertForbidden();
        $this->assertSame(HelpApplicationStatus::Pending, $application->refresh()->status);
        $this->assertNull($document->refresh()->removed_at);
    }

    public function test_exact_consent_is_unchecked_only_on_the_private_draft_page(): void
    {
        $applicant = User::factory()->user()->create();
        $application = HelpApplication::factory()->create(['applicant_id' => $applicant->id]);
        $response = $this->actingAs($applicant)->get(route('help-applications.edit', $application));

        $english = '“I confirm that the information I provided is accurate to the best of my knowledge. I consent to the Al-Kalimah Foundation securely storing and processing my private personal information, identity information, story, and supporting documents solely to review and manage my request for assistance. I understand that submitting the application locks the current draft, does not guarantee approval, and does not automatically publish my story, identity, or documents.”';
        $arabic = '“أؤكد أن المعلومات التي قدمتها صحيحة حسب علمي، وأوافق على قيام مؤسسة الكلمة بحفظ ومعالجة بياناتي الشخصية الخاصة ومعلومات هويتي وقصتي ومستنداتي الداعمة بصورة آمنة، وذلك فقط لمراجعة طلب المساعدة وإدارته. وأفهم أن إرسال الطلب يؤدي إلى قفل المسودة الحالية، ولا يضمن قبول الطلب، ولا ينشر قصتي أو هويتي أو مستنداتي تلقائيًا.”';

        $response->assertOk()
            ->assertSee($english)
            ->assertSee($arabic)
            ->assertSee('aria-describedby="consent-en consent-ar', false)
            ->assertSee('<label for="consent"', false)
            ->assertSee('name="consent"', false)
            ->assertSee('name="consent" type="checkbox" value="1"', false)
            ->assertSeeInOrder([$english, $arabic, 'name="consent"'], false)
            ->assertDontSee('name="consent" type="checkbox" value="1" checked', false);
        $this->assertSame(1, substr_count($response->getContent(), 'name="consent"'));

        $submissionAction = route('help-applications.submit', $application);
        $matched = preg_match(
            '~<form method="POST" action="'.preg_quote($submissionAction, '~').'" class="mt-5 space-y-4">(.*?)</form>~s',
            $response->getContent(),
            $submissionForm,
        );
        $this->assertSame(1, $matched);
        $this->assertSame(1, substr_count($submissionForm[1], '<button'));
        $this->assertMatchesRegularExpression('~<button type="submit" class="[^"]*\bbg-indigo-600\b[^"]*">Submit Application / <span lang="ar" dir="rtl">إرسال الطلب</span></button>~', $submissionForm[1]);
        $this->assertStringNotContainsString('bg-emerald-700', $submissionForm[1]);
    }

    private function readyApplication(User $applicant, bool $storeBytes = true): HelpApplication
    {
        $identity = 'SD-123456';
        $blindIndex = app(IdentityBlindIndex::class);
        $version = $blindIndex->currentKeyVersion();
        $application = HelpApplication::factory()->create([
            'applicant_id' => $applicant->id,
            'identity_document_type' => IdentityDocumentType::NationalId,
            'identity_issuing_country' => 'SD',
            'identity_document_number' => $identity,
            'identity_blind_index_version' => $version,
            'identity_blind_index' => $blindIndex->compute(IdentityDocumentType::NationalId, 'SD', $identity, $version),
        ]);
        $this->addValidDocument($application, $storeBytes);

        return $application;
    }

    private function addValidDocument(HelpApplication $application, bool $storeBytes = true): HelpApplicationDocument
    {
        $bytes = 'private verified supporting bytes';
        $document = HelpApplicationDocument::factory()->acceptedUnscanned()->medicalReport()->create([
            'help_application_id' => $application->id,
            'size_bytes' => strlen($bytes),
            'checksum' => hash('sha256', $bytes),
        ]);
        $this->assertSame(
            HelpApplicationDocumentPath::make($application->reference, $document->reference, 'pdf'),
            $document->storage_path,
        );
        if ($storeBytes) {
            Storage::disk('help_application_documents')->put($document->storage_path, $bytes);
        }

        return $document;
    }
}
