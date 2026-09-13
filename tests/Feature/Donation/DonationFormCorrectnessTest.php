<?php

namespace Tests\Feature\Donation;

use App\Contracts\DonationGateway;
use App\Enums\HelpApplicationStatus;
use App\Models\Campaign;
use App\Models\Donation;
use App\Models\HelpApplication;
use App\Models\User;
use App\Services\InternalNotificationProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DonationFormCorrectnessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['donations.driver' => 'sandbox']);
    }

    private function campaign(): Campaign
    {
        return Campaign::factory()->active()->create(['target_amount' => '100.00', 'raised_amount' => '0.00', 'expires_at' => now()->addDay()]);
    }

    private function form(Campaign $campaign): string
    {
        $response = $this->get('/en/cases/'.$campaign->slug.'/donate')->assertOk();
        $token = $response->viewData('idempotencyToken');
        $response->assertSee('name="idempotency_token" value="'.$token.'"', false);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $token);

        return $token;
    }

    public function test_independent_account_forms_and_each_form_replay_are_distinct(): void
    {
        $this->actingAs(User::factory()->create());
        $campaign = $this->campaign();
        $first = $this->form($campaign);
        $second = $this->form($campaign);
        $this->assertNotSame($first, $second);
        $urls = [];
        foreach ([$first, $second] as $token) {
            $input = ['amount' => '10', 'anonymous' => '0', 'idempotency_token' => $token];
            $url = $this->post('/en/cases/'.$campaign->slug.'/donate', $input)->assertRedirect()->headers->get('Location');
            $this->post('/en/cases/'.$campaign->slug.'/donate', $input)->assertRedirect($url);
            $urls[] = $url;
        }
        $this->assertNotSame(...$urls);
        $this->assertDatabaseCount('donations', 2);
        $this->assertDatabaseCount('payment_attempts', 2);
    }

    public function test_tokens_are_bound_to_campaign_and_session(): void
    {
        $campaign = $this->campaign();
        $other = $this->campaign();
        $token = $this->form($campaign);
        $this->postJson('/en/cases/'.$other->slug.'/donate', ['amount' => '10', 'idempotency_token' => $token])->assertUnprocessable()->assertJsonValidationErrors('idempotency_token');
        $this->app['session']->flush();
        $this->app['session']->regenerate();
        $this->postJson('/en/cases/'.$campaign->slug.'/donate', ['amount' => '10', 'idempotency_token' => $token])->assertUnprocessable()->assertJsonValidationErrors('idempotency_token');
        $this->assertDatabaseCount('donations', 0);
        $this->assertDatabaseCount('payment_attempts', 0);
    }

    #[DataProvider('invalidTokens')]
    public function test_invalid_tokens_fail_without_flashing_or_echoing(mixed $token): void
    {
        $campaign = $this->campaign();
        $this->form($campaign);
        $input = ['amount' => '10'];
        if ($token !== null) {
            $input['idempotency_token'] = $token;
        }
        $response = $this->postJson('/en/cases/'.$campaign->slug.'/donate', $input)->assertUnprocessable()->assertJsonValidationErrors('idempotency_token');
        if (is_string($token) && $token !== '') {
            $response->assertDontSee($token);
        }
        $this->post('/en/cases/'.$campaign->slug.'/donate', $input)->assertSessionHasErrors('idempotency_token')->assertSessionMissing('_old_input');
        $this->assertDatabaseCount('donations', 0);
        $this->assertDatabaseCount('payment_attempts', 0);
    }

    public static function invalidTokens(): array
    {
        return [[null], [''], ['malformed-secret'], [str_repeat('a', 64)], [str_repeat('A', 64)], [str_repeat('a', 63)], [['secret']], [123], [' '.str_repeat('a', 64)]];
    }

    public function test_expiry_and_session_capacity_reject_abandoned_forms(): void
    {
        $campaign = $this->campaign();
        $first = $this->form($campaign);
        for ($i = 0; $i < 32; $i++) {
            $last = $this->form($campaign);
        }
        $this->assertCount(32, session('donation_form_tokens'));
        $this->postJson('/en/cases/'.$campaign->slug.'/donate', ['amount' => '10', 'idempotency_token' => $first])->assertUnprocessable();
        $this->travel(30)->minutes();
        $this->postJson('/en/cases/'.$campaign->slug.'/donate', ['amount' => '10', 'idempotency_token' => $last])->assertUnprocessable();
        $this->assertSame([], session('donation_form_tokens'));
        $this->assertDatabaseCount('donations', 0);
    }

    public function test_authenticated_anonymous_is_required_and_extra_input_is_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        $campaign = $this->campaign();
        $token = $this->form($campaign);
        $this->postJson('/en/cases/'.$campaign->slug.'/donate', ['amount' => '10', 'idempotency_token' => $token])->assertUnprocessable()->assertJsonValidationErrors('anonymous');
        $this->postJson('/en/cases/'.$campaign->slug.'/donate', ['amount' => '10', 'anonymous' => '0', 'anonymous_extra' => '1', 'idempotency_token' => $token])->assertUnprocessable();
        $this->assertDatabaseCount('donations', 0);
    }

    public function test_checkout_creation_failure_does_not_log_or_flash_the_form_token(): void
    {
        $campaign = $this->campaign();
        $token = $this->form($campaign);
        Log::shouldReceive('warning')->once()->with('Sandbox donation operation failed.');
        Log::shouldReceive('error')->never();
        $gateway = $this->mock(DonationGateway::class);
        $gateway->shouldReceive('provider')->twice()->andReturn('sandbox');
        $gateway->shouldReceive('reference')->once()->andThrow(new \RuntimeException($token));
        $this->post('/en/cases/'.$campaign->slug.'/donate', ['amount' => '10', 'idempotency_token' => $token])
            ->assertStatus(500)->assertDontSee($token)->assertSessionMissing('_old_input');
        $this->assertDatabaseCount('donations', 0);
        $this->assertDatabaseCount('payment_attempts', 0);
    }

    public function test_form_token_never_leaks_after_checkout_or_into_persistence_and_logs(): void
    {
        Log::spy();
        $application = HelpApplication::factory()->create(['status' => HelpApplicationStatus::CampaignActive]);
        $campaign = $this->campaign();
        $campaign->forceFill(['help_application_id' => $application->id])->save();
        $token = $this->form($campaign);
        $response = $this->post('/en/cases/'.$campaign->slug.'/donate', ['amount' => '100', 'idempotency_token' => $token])->assertRedirect()->assertSessionMissing('_old_input')->assertDontSee($token);
        $url = $response->headers->get('Location');
        $this->assertStringNotContainsString($token, $url);
        $this->get($url)->assertOk()->assertDontSee($token)->assertDontSee('idempotency_token');
        $donation = Donation::firstOrFail();
        $capability = basename($url);
        $result = $this->post(route('donations.outcome', ['locale' => 'en', 'donation' => $donation->reference, 'capability' => $capability, 'action' => 'success']))->assertRedirect()->assertDontSee($token);
        $resultUrl = $result->headers->get('Location');
        $this->assertStringNotContainsString($token, $resultUrl);
        $this->get($resultUrl)->assertOk()->assertDontSee($token)->assertDontSee('idempotency_token');
        app(InternalNotificationProjector::class)->projectReady();
        $this->assertDatabaseCount('internal_notifications', 1);
        foreach (['donations', 'payment_attempts', 'audit_logs', 'internal_notification_events', 'internal_notification_event_recipients', 'internal_notifications'] as $table) {
            $this->assertStringNotContainsString($token, json_encode(DB::table($table)->get()));
        }
        $this->assertStringNotContainsString($token, $donation->fresh()->load('paymentAttempt')->toJson());
        $this->assertDatabaseCount('internal_notification_events', 1);
        foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency', 'log'] as $level) {
            Log::shouldNotHaveReceived($level);
        }
        // Form expiry never invalidates the separate guest checkout capability.
        $this->travel(31)->minutes();
        $this->get($resultUrl)->assertOk();
    }
}
