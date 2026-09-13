<?php

namespace Tests\Feature\Donation;

use App\Enums\CampaignStatus;
use App\Enums\DonationStatus;
use App\Enums\HelpApplicationStatus;
use App\Enums\InternalNotificationType;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Donation;
use App\Models\HelpApplication;
use App\Models\InternalNotification;
use App\Models\InternalNotificationEvent;
use App\Models\InternalNotificationEventRecipient;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\InternalNotificationProjector;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SandboxDonationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['donations.driver' => 'sandbox']);
        $this->freezeSecond();
    }

    private function campaign(array $attributes = []): Campaign
    {
        return Campaign::factory()->active()->create($attributes + ['target_amount' => '100.00', 'raised_amount' => '0.00', 'expires_at' => now()->addDay()]);
    }

    private function begin(Campaign $campaign, string $amount = '10.00'): array
    {
        $formToken = $this->formToken($campaign);
        $response = $this->post('/en/cases/'.$campaign->slug.'/donate', ['amount' => $amount, 'idempotency_token' => $formToken] + (auth()->check() ? ['anonymous' => '0'] : []))->assertRedirect();
        $donation = Donation::query()->latest('id')->firstOrFail();
        $url = $response->headers->get('Location');

        return [$donation, $url, $donation->donor_id === null ? basename($url) : null, $formToken];
    }

    private function formToken(Campaign $campaign): string
    {
        return $this->get('/en/cases/'.$campaign->slug.'/donate')->assertOk()->viewData('idempotencyToken');
    }

    private function outcome(Donation $donation, ?string $token, string $action = 'success'): string
    {
        return route('donations.outcome', ['locale' => 'en', 'donation' => $donation->reference, 'action' => $action, 'capability' => $token]);
    }

    private function resultUrl(Donation $donation, ?string $token): string
    {
        return route('donations.show', ['locale' => 'en', 'donation' => $donation->reference, 'capability' => $token]);
    }

    public function test_guest_foundation_checkout_and_minimal_result(): void
    {
        $campaign = $this->campaign();
        [$donation, $url, $token] = $this->begin($campaign, '0.01');
        $this->assertTrue(Schema::hasColumns('donations', ['reference', 'campaign_id', 'donor_id', 'amount', 'capability_hash', 'paid_at']));
        $this->assertTrue(Schema::hasColumns('payment_attempts', ['reference', 'donation_id', 'provider_reference', 'status']));
        $this->assertSame('reference', $donation->getRouteKeyName());
        $this->assertSame('reference', $donation->paymentAttempt->getRouteKeyName());
        $this->assertSame(DonationStatus::Pending, $donation->status);
        $this->assertSame('0.01', $donation->amount);
        $this->assertSame('SDG', $donation->currency);
        $this->assertNull($donation->donor_id);
        $this->assertFalse($donation->anonymous);
        $this->assertSame(hash('sha256', $token), $donation->capability_hash);
        $this->assertSame($campaign->id, $donation->campaign->id);
        $this->assertSame($donation->id, $campaign->donations()->firstOrFail()->id);
        $this->assertSame($donation->id, $donation->paymentAttempt->donation->id);
        foreach (['campaign_id', 'donor_id', 'amount', 'status', 'currency', 'capability_hash', 'paid_at'] as $field) {
            $this->assertFalse($donation->isFillable($field));
        }
        foreach (['donation_id', 'status', 'provider_reference'] as $field) {
            $this->assertFalse($donation->paymentAttempt->isFillable($field));
        }
        foreach (['id', 'donor_id', 'campaign_id', 'capability_hash', 'campaign', 'payment_attempt'] as $field) {
            $this->assertArrayNotHasKey($field, $donation->toArray());
        }
        foreach (['id', 'donation_id', 'provider_reference', 'provider', 'donation'] as $field) {
            $this->assertArrayNotHasKey($field, $donation->paymentAttempt->toArray());
        }
        $page = $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')->assertSee('No real financial transaction')
            ->assertDontSee($donation->paymentAttempt->provider_reference)->assertDontSee('name="card', false)->assertDontSee('name="amount', false);
        $this->assertSame(3, substr_count($page->getContent(), 'method="post"'));
        $this->get($this->resultUrl($donation, $token))->assertOk()->assertSee('Pending')->assertDontSee($campaign->slug)->assertDontSee($token);
        $this->assertDatabaseCount('internal_notifications', 0);
        $this->assertSame('0.00', $campaign->fresh()->raised_amount);
    }

    #[DataProvider('invalidInputs')]
    public function test_strict_input_rejection_without_flashing_or_creating(array $input): void
    {
        $campaign = $this->campaign();
        $response = $this->postJson('/en/cases/'.$campaign->slug.'/donate', $input + ['idempotency_token' => $this->formToken($campaign)])->assertUnprocessable();
        $this->assertDatabaseCount('donations', 0);
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertNull(session()->getOldInput('card_number'));
        $response->assertDontSee('4111111111111111');
    }

    public static function invalidInputs(): array
    {
        $cases = ['missing amount' => [[]]];
        foreach ([null, '', '0', '0.00', '-1', '100.01', '1e2', '1,00', '1,000.00', '١٠', '+10', '01', ' 10', '10 ', '1.000', '99999999999999999.99', ['10'], 10, 10.5] as $i => $amount) {
            $cases['amount '.$i] = [['amount' => $amount]];
        }
        foreach (['card_number', 'cvv', 'bank_credentials', 'phone', 'email', 'address', 'identity_document', 'applicant_id', 'donor_id', 'status', 'currency', 'campaign_id', 'paid_at', 'capability_hash'] as $key) {
            $cases[$key] = [['amount' => '10.00', $key => '4111111111111111']];
        }
        foreach (['1', '0', true, false, null, []] as $i => $anonymous) {
            $cases['guest anonymous '.$i] = [['amount' => '10', 'anonymous' => $anonymous]];
        }

        return $cases;
    }

    public function test_html_rejection_flashes_errors_only(): void
    {
        $campaign = $this->campaign();
        $this->post('/en/cases/'.$campaign->slug.'/donate', ['amount' => '4111111111111111', 'card_number' => '4111111111111111', 'idempotency_token' => $this->formToken($campaign)])->assertSessionHasErrors('amount')->assertSessionMissing('_old_input');
        $this->post('/en/cases/'.$campaign->slug.'/donate', ['amount' => '100.01', 'idempotency_token' => $this->formToken($campaign)])->assertSessionHasErrors('amount')->assertSessionMissing('_old_input');
        $this->assertDatabaseCount('donations', 0);
    }

    #[DataProvider('anonymousInputs')]
    public function test_account_anonymity_is_canonical(mixed $anonymous, bool $valid): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $campaign = $this->campaign();
        $response = $this->postJson('/en/cases/'.$campaign->slug.'/donate', ['amount' => '10', 'anonymous' => $anonymous, 'idempotency_token' => $this->formToken($campaign)]);
        if (! $valid) {
            $response->assertUnprocessable();
            $this->assertDatabaseCount('donations', 0);

            return;
        }
        $response->assertRedirect();
        $donation = Donation::firstOrFail();
        $this->assertSame($user->id, $donation->donor_id);
        $this->assertSame(in_array($anonymous, [true, '1', 1], true), $donation->anonymous);
        $this->assertNull($donation->capability_hash);
        $this->assertSame($user->id, $donation->donor->id);
        $this->assertSame($donation->id, $user->donations()->firstOrFail()->id);
    }

    public static function anonymousInputs(): array
    {
        return [[true, false], [false, false], ['1', true], ['0', true], [1, false], [0, false], ['true', false], ['yes', false], ['on', false], [[], false], [null, false], ['', false]];
    }

    #[DataProvider('hiddenCampaigns')]
    public function test_ineligible_campaign_is_concealed_on_get_and_post(array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            if ($value === 'PAST') {
                $attributes[$key] = now()->subSecond();
            } if ($value === 'FUTURE') {
                $attributes[$key] = now()->addSecond();
            }
        }
        $campaign = $this->campaign($attributes);
        $this->get('/en/cases/'.$campaign->slug.'/donate')->assertNotFound();
        $this->post('/en/cases/'.$campaign->slug.'/donate', ['amount' => '1'])->assertNotFound();
        $this->assertDatabaseCount('donations', 0);
    }

    public static function hiddenCampaigns(): array
    {
        return [[['status' => 'draft']], [['status' => 'paused']], [['status' => 'funded']], [['status' => 'cancelled']], [['status' => 'completed']], [['status' => 'aid_delivery']], [['expires_at' => 'PAST']], [['published_at' => 'FUTURE']], [['published_at' => null]], [['deleted_at' => 'PAST']], [['target_amount' => '0']], [['raised_amount' => '101']], [['raised_amount' => '-1']]];
    }

    public function test_inactive_and_deleted_category_cannot_receive_donations(): void
    {
        $campaign = $this->campaign();
        $campaign->category->forceFill(['is_active' => false])->save();
        $this->post('/en/cases/'.$campaign->slug.'/donate', ['amount' => '1'])->assertNotFound();
        $campaign->category->forceFill(['is_active' => true, 'deleted_at' => now()])->save();
        $this->get('/en/cases/'.$campaign->slug.'/donate')->assertNotFound();
    }

    public function test_success_replay_and_finality_are_atomic_and_public_progress_refreshes(): void
    {
        $campaign = $this->campaign(['raised_amount' => '99.90']);
        [$donation,$url,$token] = $this->begin($campaign, '0.10');
        $this->post($this->outcome($donation, $token))->assertRedirect($this->resultUrl($donation, $token));
        $this->assertSame('100.00', $campaign->fresh()->raised_amount);
        $this->assertSame(CampaignStatus::Funded, $campaign->fresh()->status);
        $this->assertSame(DonationStatus::Succeeded, $donation->fresh()->status);
        $this->assertSame($donation->fresh()->paid_at->toISOString(), $campaign->fresh()->funded_at->toISOString());
        $this->assertSame($donation->fresh()->completed_at->toISOString(), $donation->paymentAttempt->fresh()->completed_at->toISOString());
        foreach (['success', 'success', 'failure', 'cancellation'] as $action) {
            $this->post($this->outcome($donation, $token, $action))->assertRedirect();
        }
        $this->get($url)->assertRedirect($this->resultUrl($donation, $token));
        $this->get($this->resultUrl($donation, $token))->assertOk()->assertSee('Succeeded');
        $this->assertSame('100.00', $campaign->fresh()->raised_amount);
        $this->assertDatabaseCount('audit_logs', 3);
        $this->assertDatabaseCount('internal_notification_events', 0);
        foreach (['/en/cases', '/en/cases/'.$campaign->slug, '/en'] as $path) {
            $this->get($path)->assertOk()->assertSee('100.00 SDG')->assertSee('Campaign fully funded.')->assertDontSee('/'.$campaign->slug.'/donate');
        }
        $this->post('/en/cases/'.$campaign->slug.'/donate', ['amount' => '0.01'])->assertNotFound();
    }

    #[DataProvider('nonSuccessOutcomes')]
    public function test_non_success_outcomes_are_final_without_accounting(string $action, string $status): void
    {
        $campaign = $this->campaign();
        [$donation,$url,$token] = $this->begin($campaign);
        $this->post($this->outcome($donation, $token, $action))->assertRedirect();
        $this->post($this->outcome($donation, $token))->assertRedirect();
        $this->assertSame($status, $donation->fresh()->status->value);
        $this->assertSame($status, $donation->paymentAttempt->fresh()->status->value);
        $this->assertNull($donation->fresh()->paid_at);
        $this->assertSame('0.00', $campaign->fresh()->raised_amount);
        $this->assertDatabaseCount('audit_logs', 2);
        $this->assertDatabaseCount('internal_notification_events', 0);
    }

    public static function nonSuccessOutcomes(): array
    {
        return [['failure', 'failed'], ['cancellation', 'cancelled']];
    }

    public function test_two_pending_attempts_cannot_overfund_and_time_expiry_occurs_only_on_post(): void
    {
        $campaign = $this->campaign();
        [$first,,$token1] = $this->begin($campaign, '60');
        [$second,,$token2] = $this->begin($campaign, '60');
        $this->post($this->outcome($first, $token1))->assertRedirect();
        $this->post($this->outcome($second, $token2))->assertRedirect();
        $this->assertSame('60.00', $campaign->fresh()->raised_amount);
        $this->assertSame(DonationStatus::Expired, $second->fresh()->status);
        [$third,$url,$token3] = $this->begin($campaign, '40');
        $this->travel(31)->minutes();
        $this->get($url)->assertOk();
        $this->assertSame(DonationStatus::Pending, $third->fresh()->status);
        $this->post($this->outcome($third, $token3))->assertRedirect();
        $this->assertSame(DonationStatus::Expired, $third->fresh()->status);
        $this->assertSame('60.00', $campaign->fresh()->raised_amount);
    }

    public function test_fresh_eligibility_and_another_checkout_after_partial_success(): void
    {
        $campaign = $this->campaign();
        [$first,,$token] = $this->begin($campaign, '40');
        $this->post($this->outcome($first, $token))->assertRedirect();
        [$second,,$token2] = $this->begin($campaign, '60');
        $campaign->forceFill(['status' => 'paused'])->save();
        $this->post($this->outcome($second, $token2))->assertRedirect();
        $this->assertSame(DonationStatus::Expired, $second->fresh()->status);
        $this->assertSame('40.00', $campaign->fresh()->raised_amount);
    }

    public function test_linked_funding_keeps_application_status_and_projects_one_durable_notification(): void
    {
        $application = HelpApplication::factory()->create(['status' => HelpApplicationStatus::CampaignActive, 'private_story' => 'SECRET STORY', 'decision_note' => 'SECRET NOTE']);
        $campaign = $this->campaign(['help_application_id' => $application->id]);
        $before = $application->fresh()->getRawOriginal();
        [$donation,,$token] = $this->begin($campaign, '100');
        $this->post($this->outcome($donation, $token))->assertRedirect();
        $this->assertSame($before, $application->fresh()->getRawOriginal());
        $this->assertDatabaseCount('internal_notification_events', 1);
        $this->assertDatabaseCount('internal_notification_event_recipients', 1);
        $event = InternalNotificationEvent::firstOrFail();
        $this->assertSame($campaign->fresh()->funded_at->toISOString(), $event->occurred_at->toISOString());
        // RefreshDatabase keeps an outer transaction open: exercise the retryable projector explicitly.
        app(InternalNotificationProjector::class)->projectEvent($event);
        app(InternalNotificationProjector::class)->projectEvent($event);
        $this->post($this->outcome($donation, $token))->assertRedirect();
        $this->assertDatabaseCount('internal_notifications', 1);
        $notification = InternalNotification::firstOrFail();
        $this->assertSame($application->applicant_id, $notification->recipient_id);
        $this->assertSame(InternalNotificationType::CampaignFundingCompleted, $notification->type);
        $this->assertSame(['funding_reference' => $event->reference, 'status' => 'funded'], $notification->data);
        $this->assertDatabaseCount('internal_notification_events', 1);
        $this->assertDatabaseCount('internal_notification_event_recipients', 1);
        $this->assertSame(0, AuditLog::where('subject_type', HelpApplication::class)->count());
        foreach (AuditLog::all() as $audit) {
            $this->assertNull($audit->actor_id);
            $this->assertSame(['status'], array_keys($audit->new_values));
        }
        $this->get($this->resultUrl($donation, $token))->assertDontSee('SECRET')->assertDontSee($application->reference)->assertDontSee($event->reference);
    }

    public function test_guest_capability_and_account_ownership_conceal_cross_access(): void
    {
        $campaign = $this->campaign();
        [$guest,$url,$token] = $this->begin($campaign);
        foreach ([null, str_repeat('a', 64)] as $wrong) {
            $this->get($this->resultUrl($guest, $wrong))->assertNotFound();
            $this->post($this->outcome($guest, $wrong))->assertNotFound();
        }
        $owner = User::factory()->create();
        $this->actingAs($owner);
        [$account,$accountUrl] = $this->begin($campaign);
        $this->get($accountUrl)->assertOk();
        $this->get('/en/my-donations')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertSee($account->reference)->assertDontSee($guest->reference);
        $other = User::factory()->create();
        $this->actingAs($other);
        $this->get($accountUrl)->assertNotFound();
        $this->get($this->resultUrl($account, null))->assertNotFound();
        $this->post($this->outcome($account, null))->assertNotFound();
        $this->get('/en/my-donations')->assertOk()->assertDontSee($account->reference);
        $this->get($url)->assertOk(); // Valid guest capability remains independent of an account.
        $this->assertSame(DonationStatus::Pending, $account->fresh()->status);
    }

    public function test_routes_driver_and_get_requests_have_no_mutations(): void
    {
        $campaign = $this->campaign();
        [$donation,$url,$token] = $this->begin($campaign);
        $before = $donation->getRawOriginal();
        foreach (['success', 'failure', 'cancellation'] as $action) {
            $this->get($this->outcome($donation, $token, $action))->assertStatus(405);
        }
        $this->get($url.'?outcome=success')->assertOk();
        $this->get($this->resultUrl($donation, $token))->assertOk();
        $this->assertSame($before, $donation->fresh()->getRawOriginal());
        $this->assertDatabaseCount('audit_logs', 0);
        $this->post($this->outcome($donation, $token), ['card_number' => '4111111111111111'])->assertUnprocessable();
        foreach (['fr', 'EN'] as $locale) {
            $this->get('/'.$locale.'/my-donations')->assertNotFound();
            $this->post('/'.$locale.'/cases/'.$campaign->slug.'/donate', ['amount' => '1'])->assertNotFound();
        }
        foreach (['checkout', 'show', 'outcome'] as $name) {
            $route = Route::getRoutes()->getByName('donations.'.$name);
            $this->assertContains('web', $route->gatherMiddleware());
            $this->assertContains('throttle:donation-'.($name === 'show' ? 'result' : $name), $route->gatherMiddleware());
            $this->assertSame($name === 'outcome' ? ['POST'] : ['GET', 'HEAD'], $route->methods());
        }
        config(['donations.driver' => 'disabled']);
        $this->get($url)->assertNotFound();
        $this->post($this->outcome($donation, $token))->assertNotFound();
    }

    public function test_database_rejects_second_attempt_invalid_currency_and_orphan(): void
    {
        $campaign = $this->campaign();
        [$donation] = $this->begin($campaign);
        $attempt = $donation->paymentAttempt;
        foreach ([fn () => DB::table('payment_attempts')->insert(array_merge($attempt->getRawOriginal(), ['id' => 999, 'reference' => (string) Str::uuid(), 'provider_reference' => (string) Str::uuid()])),
            fn () => DB::table('donations')->where('id', $donation->id)->update(['currency' => 'SAR']),
            fn () => DB::table('donations')->where('id', $donation->id)->update(['amount' => '0.00']),
            fn () => DB::table('donations')->where('id', $donation->id)->update(['campaign_id' => 999999])] as $operation) {
            try {
                $operation();
                $this->fail('Constraint did not reject invalid state');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_repeated_entry_submission_reuses_one_pending_donation_and_attempt(): void
    {
        $campaign = $this->campaign();
        [$donation,$url,$token,$formToken] = $this->begin($campaign, '10');
        $this->post('/en/cases/'.$campaign->slug.'/donate', ['amount' => '10.00', 'idempotency_token' => $formToken])->assertRedirect($url);
        $this->assertDatabaseCount('donations', 1);
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->post($this->outcome($donation, $token))->assertRedirect();
        $this->post('/en/cases/'.$campaign->slug.'/donate', ['amount' => '10.00', 'idempotency_token' => $formToken])->assertRedirect($url);
        $this->post($this->outcome($donation, $token))->assertRedirect();
        $this->assertSame('10.00', $campaign->fresh()->raised_amount);
        $this->assertDatabaseCount('donations', 1);
    }

    public function test_account_success_is_private_and_guests_receive_no_user_confirmation(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $campaign = $this->campaign();
        [$donation,$url] = $this->begin($campaign);
        $this->post($this->outcome($donation, null))->assertRedirect();
        $this->get($this->resultUrl($donation, null))->assertOk()->assertSee('Succeeded')->assertDontSee($user->name)->assertDontSee($user->email);
        $this->get('/en/my-donations')->assertOk()->assertSee($donation->reference)->assertSee('Succeeded');
        // Existing events require an Application subject. Account confirmation is the private result/history.
        $this->assertDatabaseCount('internal_notification_events', 0);
        $this->assertDatabaseCount('internal_notifications', 0);
    }

    public function test_accounting_rolls_back_when_audit_persistence_fails(): void
    {
        $campaign = $this->campaign();
        [$donation,,$token,$formToken] = $this->begin($campaign);
        Log::shouldReceive('warning')->once()->with('Sandbox donation operation failed.');
        Log::shouldReceive('error')->never();
        $this->mock(AuditLogger::class)->shouldReceive('log')->once()->andThrow(new \RuntimeException('Persistence failed'));
        $this->post($this->outcome($donation, $token))->assertStatus(500)->assertDontSee($token);
        $this->assertSame(DonationStatus::Pending, $donation->fresh()->status);
        $this->assertSame(DonationStatus::Pending, $donation->paymentAttempt->fresh()->status);
        $this->assertSame('0.00', $campaign->fresh()->raised_amount);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('internal_notification_events', 0);
    }

    public function test_notification_failure_preserves_intent_and_later_projection_recovers(): void
    {
        $application = HelpApplication::factory()->create(['status' => HelpApplicationStatus::CampaignActive]);
        $campaign = $this->campaign(['help_application_id' => $application->id]);
        [$donation,,$token] = $this->begin($campaign, '100');
        $originalReference = null;
        InternalNotificationEventRecipient::created(function ($intent) use (&$originalReference) {
            $originalReference = InternalNotificationEvent::findOrFail($intent->event_id)->reference;
            DB::table('internal_notification_events')->where('id', $intent->event_id)->update(['reference' => 'invalid-reference']);
        });
        $this->post($this->outcome($donation, $token))->assertRedirect();
        $event = InternalNotificationEvent::firstOrFail();
        $intent = InternalNotificationEventRecipient::firstOrFail();
        $this->assertDatabaseCount('internal_notifications', 0);
        $this->assertSame('pending', $intent->state->value);
        $this->assertSame(1, $intent->attempts);
        DB::table('internal_notification_events')->where('id', $event->id)->update(['reference' => $originalReference]);
        $this->travel(61)->seconds();
        app(InternalNotificationProjector::class)->projectReady();
        app(InternalNotificationProjector::class)->projectReady();
        $this->assertDatabaseCount('internal_notifications', 1);
        $this->assertSame('100.00', $campaign->fresh()->raised_amount);
    }

    public function test_private_history_is_paginated_and_guests_authenticate(): void
    {
        $this->get('/en/my-donations')->assertRedirect(route('login'));
        $user = User::factory()->create();
        $this->actingAs($user);
        $campaign = $this->campaign(['target_amount' => '1000.00']);
        for ($i = 0; $i < 16; $i++) {
            $this->travel(61)->seconds();
            $this->begin($campaign);
        }
        $page = $this->get('/en/my-donations')->assertOk();
        $this->assertCount(15, $page->viewData('donations'));
        $this->assertSame(16, $page->viewData('donations')->total());
        $this->get('/en/my-donations?page=2')->assertOk()->assertViewHas('donations', fn ($items) => $items->count() === 1);
    }

    public function test_csrf_and_entry_and_outcome_rate_limits(): void
    {
        $campaign = $this->campaign();
        $environment = $this->app->environment();
        $this->app->instance('env', 'csrf-verification');
        try {
            $this->post('/en/cases/'.$campaign->slug.'/donate', ['amount' => '10'])->assertStatus(419);
        } finally {
            $this->app->instance('env', $environment);
        }
        $this->travel(61)->seconds();
        [$donation,,$token,$formToken] = $this->begin($campaign);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/en/cases/'.$campaign->slug.'/donate', ['amount' => '10', 'idempotency_token' => $formToken])->assertRedirect();
        }
        $this->post('/en/cases/'.$campaign->slug.'/donate', ['amount' => '10', 'idempotency_token' => $formToken])->assertStatus(429);
        for ($i = 0; $i < 10; $i++) {
            $this->post($this->outcome($donation, $token))->assertRedirect();
        }
        $this->post($this->outcome($donation, $token))->assertStatus(429);
        $this->assertSame('10.00', $campaign->fresh()->raised_amount);
    }

    public function test_money_precision_indexes_and_rollback_only_added_tables(): void
    {
        $campaign = $this->campaign();
        [$donation] = $this->begin($campaign);
        DB::table('donations')->where('id', $donation->id)->update(['amount' => '9999999999999999.99']);
        $this->assertSame('9999999999999999.99', $donation->fresh()->amount);
        $this->assertTrue(collect(Schema::getIndexes('donations'))->contains(fn ($index) => $index['unique'] && $index['columns'] === ['entry_key']));
        $this->assertTrue(collect(Schema::getIndexes('payment_attempts'))->contains(fn ($index) => $index['unique'] && $index['columns'] === ['provider_reference']));
        $migration = require database_path('migrations/2026_09_12_000000_create_donations_and_payment_attempts.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('donations'));
        $this->assertFalse(Schema::hasTable('payment_attempts'));
        $this->assertSame(1, Campaign::count());
        $this->assertTrue(Schema::hasTable('internal_notification_events'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('donations'));
    }

    public function test_status_constraints_missing_input_canonical_references_and_query_allowlist(): void
    {
        $campaign = $this->campaign();
        [$donation,,$token,$formToken] = $this->begin($campaign);
        foreach (['donations', 'payment_attempts'] as $table) {
            try {
                DB::table($table)->where('id', 1)->update(['status' => 'refunded']);
                $this->fail('Invalid lifecycle accepted');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->postJson('/en/cases/'.$campaign->slug.'/donate?amount=10', ['amount' => '10', 'idempotency_token' => $this->formToken($campaign)])->assertUnprocessable();
        $this->get('/en/cases/INVALID/donate')->assertNotFound();
        $this->get('/en/donations/'.strtoupper($donation->reference).'/result/'.$token)->assertNotFound();
        $this->get('/en/donations/'.$donation->id.'/result/'.$token)->assertNotFound();
        $this->get($this->resultUrl($donation, $token))->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_other_guest_token_and_changed_category_or_publication_are_rechecked(): void
    {
        $campaign = $this->campaign();
        [$first,,$firstToken] = $this->begin($campaign);
        [$second,,$secondToken] = $this->begin($campaign);
        $this->get($this->resultUrl($first, $secondToken))->assertNotFound();
        $this->post($this->outcome($first, $secondToken))->assertNotFound();
        $campaign->category->forceFill(['is_active' => false])->save();
        $this->post($this->outcome($first, $firstToken))->assertRedirect();
        $this->assertSame(DonationStatus::Expired, $first->fresh()->status);
        $campaign->category->forceFill(['is_active' => true])->save();
        $campaign->forceFill(['published_at' => now()->addDay()])->save();
        $this->post($this->outcome($second, $secondToken))->assertRedirect();
        $this->assertSame(DonationStatus::Expired, $second->fresh()->status);
        $this->assertSame('0.00', $campaign->fresh()->raised_amount);
    }

    public function test_abandoned_checkout_expiry_is_bounded_idempotent_and_has_no_accounting_effect(): void
    {
        $campaign = $this->campaign();
        [$first] = $this->begin($campaign);
        [$second] = $this->begin($campaign);
        $this->artisan('donations:expire')->expectsOutput('expired: 0')->assertSuccessful();
        $this->travel(31)->minutes();
        $this->artisan('donations:expire', ['--limit' => '1'])->expectsOutput('expired: 1')->assertSuccessful();
        $this->assertSame(DonationStatus::Expired, $first->fresh()->status);
        $this->assertSame(DonationStatus::Pending, $second->fresh()->status);
        $this->artisan('donations:expire')->expectsOutput('expired: 1')->assertSuccessful();
        $this->artisan('donations:expire')->expectsOutput('expired: 0')->assertSuccessful();
        $this->assertSame(DonationStatus::Expired, $second->fresh()->status);
        $this->assertDatabaseCount('audit_logs', 4);
        $this->assertDatabaseCount('internal_notification_events', 0);
        $this->assertSame('0.00', $campaign->fresh()->raised_amount);
        $this->assertNull($first->fresh()->paid_at);
    }

    public function test_expiry_command_rejects_invalid_batch_limit(): void
    {
        foreach (['0', '-1', '1001', '1e2', 'invalid'] as $limit) {
            $this->artisan('donations:expire', ['--limit' => $limit])->expectsOutput('Sandbox checkout expiry could not run.')->assertFailed();
        }
        $this->assertDatabaseCount('audit_logs', 0);
    }
}
