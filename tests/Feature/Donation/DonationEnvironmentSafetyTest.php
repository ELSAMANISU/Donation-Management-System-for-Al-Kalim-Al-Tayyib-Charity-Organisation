<?php

namespace Tests\Feature\Donation;

use App\Contracts\DonationGateway;
use App\Enums\DonationStatus;
use App\Enums\HelpApplicationStatus;
use App\Models\Campaign;
use App\Models\Donation;
use App\Models\HelpApplication;
use App\Services\DonationService;
use App\Services\SandboxDonationGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DonationEnvironmentSafetyTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = ['donations', 'payment_attempts', 'campaigns', 'help_applications', 'audit_logs', 'internal_notification_events', 'internal_notification_event_recipients', 'internal_notifications'];

    private function snapshot(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), self::TABLES);
    }

    private function assertConcealed(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Disabled sandbox operation was allowed.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    #[DataProvider('environmentDrivers')]
    public function test_environment_config_is_canonical_and_defaults_disabled(?string $value, string $expected): void
    {
        // A separate process tests env() without reading or modifying any .env file.
        $process = new Process([PHP_BINARY, '-r', 'require "vendor/autoload.php"; echo json_encode(require "config/donations.php");'], base_path(), ['DONATION_DRIVER' => $value ?? false]);
        $process->mustRun();
        $configuration = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($expected, $configuration['driver']);
        config(['donations.driver' => $configuration['driver']]);
        if ($expected === 'disabled') {
            $before = $this->snapshot();
            $this->get('/en/cases/unknown/donate')->assertNotFound();
            $this->post('/en/cases/unknown/donate', ['amount' => '10'])->assertNotFound();
            $this->assertConcealed(fn () => app(DonationGateway::class));
            $this->assertConcealed(fn () => app(SandboxDonationGateway::class));
            $this->assertSame($before, $this->snapshot());
        } else {
            $this->assertInstanceOf(SandboxDonationGateway::class, app(DonationGateway::class));
        }
    }

    public static function environmentDrivers(): array
    {
        return [[null, 'disabled'], ['', 'disabled'], ['disabled', 'disabled'], ['sandbox', 'sandbox'], ['production', 'disabled'], ['Sandbox', 'disabled'], [' sandbox', 'disabled'], ['sandbox ', 'disabled'], ['false', 'disabled'], ['true', 'disabled'], ['0', 'disabled'], ['null', 'disabled']];
    }

    #[DataProvider('blockedDrivers')]
    public function test_disabled_and_unsupported_modes_conceal_all_routes_and_preserve_every_row(mixed $driver): void
    {
        config(['donations.driver' => 'sandbox']);
        $application = HelpApplication::factory()->create(['status' => HelpApplicationStatus::CampaignActive]);
        $campaign = Campaign::factory()->active()->create(['help_application_id' => $application->id, 'target_amount' => '100.00', 'raised_amount' => '0.00', 'expires_at' => now()->addDay()]);
        $formToken = $this->get('/en/cases/'.$campaign->slug.'/donate')->assertOk()->viewData('idempotencyToken');
        $checkout = $this->post('/en/cases/'.$campaign->slug.'/donate', ['amount' => '100', 'idempotency_token' => $formToken])->assertRedirect()->headers->get('Location');
        $donation = Donation::firstOrFail();
        $capability = basename($checkout);
        $result = route('donations.show', ['locale' => 'en', 'donation' => $donation->reference, 'capability' => $capability]);
        // Warm the same controller routes and retain a service before switching configuration.
        $this->get($checkout)->assertOk();
        $this->get($result)->assertOk();
        $service = app(DonationService::class);
        $before = $this->snapshot();
        config(['donations.driver' => $driver]);
        foreach (['en', 'ar'] as $locale) {
            $this->get('/'.$locale.'/cases/'.$campaign->slug.'/donate')->assertNotFound();
            $this->post('/'.$locale.'/cases/'.$campaign->slug.'/donate', ['amount' => '100', 'idempotency_token' => $formToken])->assertNotFound();
            $this->get(route('donations.checkout', ['locale' => $locale, 'donation' => $donation->reference, 'capability' => $capability]))->assertNotFound();
            $this->get(route('donations.show', ['locale' => $locale, 'donation' => $donation->reference, 'capability' => $capability]))->assertNotFound();
            $this->get('/'.$locale.'/my-donations')->assertNotFound();
            foreach (['success', 'failure', 'cancellation'] as $action) {
                $this->post(route('donations.outcome', ['locale' => $locale, 'donation' => $donation->reference, 'capability' => $capability, 'action' => $action]))->assertNotFound();
            }
        }
        $this->assertConcealed(fn () => app(DonationGateway::class));
        $this->assertConcealed(fn () => app(SandboxDonationGateway::class));
        $this->assertConcealed(fn () => $service->begin(Request::create('/'), $campaign, '100', false));
        $this->assertConcealed(fn () => $service->settle(Request::create('/'), $donation, 'success'));
        $this->assertConcealed(fn () => $service->expireDue());
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(DonationStatus::Pending, $donation->fresh()->status);
        $this->get('/en')->assertOk()->assertDontSee('/'.$campaign->slug.'/donate');
        $this->get('/en/cases/'.$campaign->slug)->assertOk()->assertDontSee('/'.$campaign->slug.'/donate');
    }

    public static function blockedDrivers(): array
    {
        return [['disabled'], [null], ['production'], ['Sandbox'], [' sandbox'], ['sandbox '], [''], [false], [0], [['sandbox']]];
    }
}
