<?php

namespace Tests\Feature\Donation;

use App\Enums\DonationStatus;
use App\Enums\HelpApplicationStatus;
use App\Models\Campaign;
use App\Models\Donation;
use App\Models\HelpApplication;
use App\Models\InternalNotification;
use App\Models\InternalNotificationEvent;
use App\Services\InternalNotificationProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DonationCommitNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['donations.driver' => 'sandbox']);
    }

    public function test_projection_runs_after_commit_and_retries_without_duplicates(): void
    {
        // Commit only the isolated SQLite test transaction to exercise real after-commit projection.
        DB::commit();
        $application = HelpApplication::factory()->create(['status' => HelpApplicationStatus::CampaignActive]);
        $campaign = Campaign::factory()->active()->create(['help_application_id' => $application->id, 'target_amount' => '10.00']);
        $formToken = $this->get('/en/cases/'.$campaign->slug.'/donate')->assertOk()->viewData('idempotencyToken');
        $response = $this->post('/en/cases/'.$campaign->slug.'/donate', ['amount' => '10', 'idempotency_token' => $formToken])->assertRedirect();
        $donation = Donation::firstOrFail();
        $token = basename($response->headers->get('Location'));
        $this->post(route('donations.outcome', ['locale' => 'en', 'donation' => $donation->reference, 'action' => 'success', 'capability' => $token]))->assertRedirect();
        $this->assertDatabaseCount('internal_notifications', 1);
        $event = InternalNotificationEvent::firstOrFail();
        $this->assertNotNull($event->projected_at);
        app(InternalNotificationProjector::class)->projectEvent($event);
        $this->assertDatabaseCount('internal_notifications', 1);
        $this->assertSame(DonationStatus::Succeeded, $donation->fresh()->status);
        $this->assertSame(HelpApplicationStatus::CampaignActive, $application->fresh()->status);
        $this->assertSame($application->applicant_id, InternalNotification::firstOrFail()->recipient_id);
    }
}
