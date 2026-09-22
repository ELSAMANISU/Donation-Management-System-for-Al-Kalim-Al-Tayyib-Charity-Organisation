<?php

namespace Tests\Feature\Admin;

use App\Data\AdministrativeReport;
use App\Enums\CampaignStatus;
use App\Enums\DonationStatus;
use App\Enums\HelpApplicationStatus;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Category;
use App\Models\HelpApplication;
use App\Models\User;
use App\Services\AdministrativeReportQuery;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdministrativeReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-22 12:00:00', 'UTC'));
    }

    private function admin(): User
    {
        $actor = User::factory()->admin()->create();
        $this->actingAs($actor);

        return $actor;
    }

    private function donation(Campaign $campaign, string $amount = '0.10', string $status = 'succeeded', ?string $paid = '2026-09-22 10:00:00', array $extra = []): int
    {
        return DB::table('donations')->insertGetId(array_replace([
            'reference' => (string) Str::uuid(), 'entry_key' => bin2hex(random_bytes(32)), 'campaign_id' => $campaign->id,
            'amount' => $amount, 'currency' => 'SDG', 'anonymous' => true, 'status' => $status,
            'paid_at' => $paid, 'completed_at' => $paid, 'expires_at' => '2026-10-01 00:00:00',
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-09-22 10:00:00',
        ], $extra));
    }

    private function report(string $query = ''): AdministrativeReport
    {
        return $this->get('/admin/reports'.$query)->assertOk()->viewData('report');
    }

    private function headers($response): void
    {
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Pragma', 'no-cache')
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_empty_report_defaults_and_minimal_audit(): void
    {
        $actor = $this->admin();
        $response = $this->get('/admin/reports')->assertOk()->assertSee('التقارير الإدارية')->assertSee('No campaigns on this page');
        $this->headers($response);
        $r = $response->viewData('report');
        $this->assertSame('2026-08-24', $r->filters['start_date']);
        $this->assertSame('2026-09-22', $r->filters['end_date']);
        $this->assertCount(30, $r->buckets);
        $this->assertSame(['count' => 0, 'amount' => '0.00'], $r->lifetime);
        $this->assertSame('0.00', $r->undelivered);
        $this->assertSame([], $r->warnings);
        $audit = AuditLog::where('action', 'reports.viewed')->sole();
        $this->assertSame($actor->id, $audit->actor_id);
        foreach (['subject_type', 'subject_id', 'old_values', 'new_values', 'ip_address', 'user_agent'] as $field) {
            $this->assertNull($audit->$field);
        }
    }

    public static function actors(): array
    {
        return [['guest', 302], ['user', 403], ['disabled', 302], ['password', 302], ['admin', 200], ['super_admin', 200]];
    }

    #[DataProvider('actors')]
    public function test_authorization_and_headers(string $kind, int $status): void
    {
        if ($kind !== 'guest') {
            $actor = User::factory()->create(['role' => $kind === 'user' ? 'user' : ($kind === 'super_admin' ? 'super_admin' : 'admin'),
                'is_active' => $kind !== 'disabled', 'must_change_password' => $kind === 'password']);
            $this->actingAs($actor);
            $this->assertSame($status === 200, Gate::forUser($actor)->allows('viewReports'));
        }
        $response = $this->get('/admin/reports')->assertStatus($status);
        $this->headers($response);
        $this->assertSame($status === 200 ? 1 : 0, AuditLog::where('action', 'reports.viewed')->count());
    }

    public static function invalidFilters(): array
    {
        return array_map(fn ($query) => [$query], [
            '?start_date=2026-09-01', '?end_date=2026-09-01', '?start_date[]=2026-09-01&end_date=2026-09-02',
            '?start_date=2026-02-30&end_date=2026-03-01', '?start_date=2026-9-01&end_date=2026-09-02',
            '?start_date=2026-09-02&end_date=2026-09-01', '?start_date=2026-09-22&end_date=2026-09-23',
            '?start_date=2025-09-20&end_date=2026-09-22', '?start_date=&end_date=',
            '?group_by=week', '?group_by[]=day', '?group_by=%20day', '?campaign_page[]=1', '?category_page=0',
            '?campaign_page=-1', '?campaign_page=1.5', '?campaign_page=10001', '?campaign_page=01', '?campaign_page=1e2',
            '?sort=amount', '?search=PRIVATE-CANARY', '?per_page=100', '?applicant=PRIVATE-CANARY', '?delivery=PRIVATE-CANARY',
        ]);
    }

    #[DataProvider('invalidFilters')]
    public function test_strict_filters_without_reflection_or_flash(string $query): void
    {
        $this->admin();
        $response = $this->get('/admin/reports'.$query)->assertStatus(422)->assertDontSee('PRIVATE-CANARY');
        $this->headers($response);
        $response->assertSessionMissing('_old_input');
        $this->assertDatabaseMissing('audit_logs', ['action' => 'reports.viewed']);
    }

    public function test_success_authority_period_boundaries_and_zero_buckets(): void
    {
        $this->admin();
        $c = Campaign::factory()->create(['raised_amount' => '0.90']);
        $this->donation($c, '0.10', paid: '2026-08-31 23:59:59');
        $this->donation($c, '0.20', paid: '2026-09-01 00:00:00');
        $this->donation($c, '0.30', paid: '2026-09-02 23:59:59');
        $this->donation($c, '0.30', paid: '2026-09-03 00:00:00');
        foreach (['pending', 'failed', 'cancelled', 'expired'] as $status) {
            $this->donation($c, '100.00', $status, null);
        }
        $r = $this->report('?start_date=2026-09-01&end_date=2026-09-02');
        $this->assertSame(['count' => 4, 'amount' => '0.90'], $r->lifetime);
        $this->assertSame('0.50', $r->period['amount']);
        $this->assertSame(2, $r->period['count']);
        $this->assertSame('0.20', $r->buckets['2026-09-01']['amount']);
        $this->assertSame('0.00', $r->campaigns[0]['difference']);
        $this->assertSame(1, $r->donationStatuses['failed']);
        $r = $this->report('?start_date=2026-09-01&end_date=2026-09-22&group_by=month');
        $this->assertSame(['2026-09'], array_keys($r->buckets));
        $this->assertSame('0.80', $r->buckets['2026-09']['amount']);
    }

    public function test_leap_day_maximum_window_and_utc_independence(): void
    {
        $this->admin();
        config(['app.timezone' => 'Asia/Singapore']);
        $r = $this->report('?start_date=2024-02-29&end_date=2025-02-28');
        $this->assertCount(366, $r->buckets);
        $this->assertSame('2026-09-22 12:00:00 UTC', $r->generatedAt);
    }

    public function test_historical_funding_category_remainder_and_archived_campaigns(): void
    {
        $this->admin();
        $active = Category::factory()->create();
        $zero = Category::factory()->create();
        $hidden = Category::factory()->create(['is_active' => false]);
        $deleted = Category::factory()->create(['deleted_at' => now()]);
        foreach ([$active, $hidden, $deleted] as $index => $category) {
            $campaign = Campaign::factory()->create(['category_id' => $category->id, 'status' => $index === 0 ? 'cancelled' : 'completed', 'deleted_at' => $index === 2 ? now() : null]);
            $this->donation($campaign, '1.00', extra: ['donor_id' => User::factory()->disabled()->create()->id]);
        }
        $r = $this->report();
        $this->assertSame('3.00', $r->lifetime['amount']);
        $this->assertSame(['count' => 2, 'amount' => '2.00'], $r->archivedCategory);
        $this->assertCount(2, $r->categories);
        $this->assertSame(1, $r->archivedCampaigns);
        $this->assertSame(1, $r->campaignStatuses['completed']);
        $this->assertTrue($r->campaigns[2]['archived']);
    }

    public function test_all_current_statuses_and_unknown_raw_values(): void
    {
        $this->admin();
        foreach (CampaignStatus::cases() as $status) {
            Campaign::factory()->create(['status' => $status]);
        }
        foreach (HelpApplicationStatus::cases() as $status) {
            HelpApplication::factory()->create(['status' => $status]);
        }
        $c = Campaign::factory()->create();
        DB::table('campaigns')->where('id', $c->id)->update(['status' => 'PRIVATE-CANARY']);
        $h = HelpApplication::factory()->create();
        DB::table('help_applications')->where('id', $h->id)->update(['status' => 'PRIVATE-CANARY']);
        DB::statement('PRAGMA ignore_check_constraints = ON');
        try {
            foreach (DonationStatus::cases() as $status) {
                $this->donation($c, '1.00', $status->value);
            } $this->donation($c, '1.00', 'PRIVATE-CANARY');
        } finally {
            DB::statement('PRAGMA ignore_check_constraints = OFF');
        }
        $response = $this->get('/admin/reports')->assertOk()->assertDontSee('PRIVATE-CANARY');
        $r = $response->viewData('report');
        foreach ([$r->campaignStatuses, $r->applicationStatuses, $r->donationStatuses] as $counts) {
            foreach ($counts as $count) {
                $this->assertSame(1, $count);
            }
        }
    }

    public static function invalidTimes(): array
    {
        return [[null], ['2026-09-23 00:00:00'], ['2026-02-30 10:00:00'], ['2025-12-31 23:59:59'], ['PRIVATE-CANARY']];
    }

    #[DataProvider('invalidTimes')]
    public function test_incoherent_payment_dates_make_period_incomplete(?string $paid): void
    {
        $this->admin();
        $c = Campaign::factory()->create();
        $this->donation($c, paid: $paid);
        $r = $this->report();
        $this->assertSame('0.10', $r->lifetime['amount']);
        $this->assertNull($r->period['amount']);
        $this->assertTrue($r->period['incomplete']);
        $this->assertArrayHasKey('invalid_payment_time', $r->warnings);
        foreach ($r->buckets as $bucket) {
            $this->assertNull($bucket['amount']);
        }
    }

    public function test_large_totals_corrupt_money_and_reconciliation_drift(): void
    {
        $this->admin();
        $c = Campaign::factory()->create(['raised_amount' => '1.00']);
        $this->donation($c, '9999999999999999.99');
        $this->donation($c, '9999999999999999.99');
        $r = $this->report();
        $this->assertSame('19999999999999999.98', $r->lifetime['amount']);
        $this->assertSame('-19999999999999998.98', $r->campaigns[0]['difference']);
        $this->donation($c, '1.001'); // SQLite's positive-value trigger intentionally does not validate canonical scale.
        $r = $this->report();
        $this->assertNull($r->lifetime['amount']);
        $this->assertNull($r->period['amount']);
        $this->assertNull($r->campaigns[0]['ledger']);
        $this->assertArrayHasKey('invalid_donation_money', $r->warnings);
    }

    public function test_navigation_head_throttling_and_generic_debug_failure(): void
    {
        $this->admin();
        $html = $this->get('/dashboard')->assertOk()->getContent();
        $this->assertSame(2, substr_count($html, route('admin.reports.index')));
        $this->headers($this->call('HEAD', '/admin/reports')->assertOk());
        for ($i = 0; $i < 9; $i++) {
            $this->get('/admin/reports')->assertOk();
        }
        $this->headers($this->get('/admin/reports')->assertStatus(429));
        $this->travel(61)->seconds();
        config(['app.debug' => true]);
        Log::spy();
        $this->mock(AuditLogger::class)->shouldReceive('log')->once()->andThrow(new \RuntimeException('PRIVATE-CANARY'));
        $response = $this->get('/admin/reports')->assertStatus(500)->assertDontSee('PRIVATE-CANARY');
        $this->headers($response);
        $response->assertSessionMissing('_old_input');
        Log::shouldNotHaveReceived('error');
        $this->actingAs(User::factory()->create());
        $this->get('/dashboard')->assertOk()->assertDontSee(route('admin.reports.index'));
    }

    private function deliveryFixture(string $state = 'simulated_delivered'): array
    {
        $category = Category::factory()->create();
        $owner = User::factory()->create(['name' => 'PRIVATE-APPLICANT-CANARY']);
        $reviewer = User::factory()->admin()->create();
        $application = HelpApplication::factory()->create(['applicant_id' => $owner->id, 'reviewed_by' => $reviewer->id,
            'status' => 'aid_delivery', 'category_id' => $category->id, 'status_changed_at' => '2026-09-20 10:00:00', 'private_story' => 'PRIVATE-STORY-CANARY']);
        $campaign = Campaign::factory()->create(['help_application_id' => $application->id, 'category_id' => $category->id,
            'status' => 'aid_delivery', 'target_amount' => '10.00', 'raised_amount' => '10.00', 'published_at' => '2026-09-18 00:00:00',
            'funded_at' => '2026-09-20 08:00:00', 'aid_delivery_started_at' => '2026-09-20 10:00:00']);
        $this->donation($campaign, '10.00', paid: '2026-09-19 10:00:00', extra: ['donor_id' => $owner->id]);
        $coordination = DB::table('assistance_coordinations')->insertGetId(['reference' => (string) Str::uuid(),
            'help_application_id' => $application->id, 'campaign_id' => $campaign->id, 'state' => 'confirmed', 'revision' => 3,
            'delivery_method' => 'cash_collection', 'delivery_details' => encrypt('PRIVATE-RECEIVING-CANARY'),
            'started_by' => $reviewer->id, 'started_at' => '2026-09-20 09:00:00', 'confirmed_by' => $reviewer->id, 'confirmed_at' => '2026-09-20 09:30:00']);
        $delivery = DB::table('aid_deliveries')->insertGetId(['reference' => (string) Str::uuid(), 'coordination_id' => $coordination,
            'amount' => '4.00', 'currency' => 'SDG', 'state' => $state, 'revision' => $state === 'in_progress' ? 1 : 2, 'entry_key' => bin2hex(random_bytes(32)),
            'started_by' => $reviewer->id, 'started_at' => '2026-09-20 10:00:00', 'completed_at' => $state === 'simulated_delivered' ? '2026-09-20 11:00:00' : null]);
        DB::table('aid_delivery_transitions')->insert(['reference' => (string) Str::uuid(), 'delivery_id' => $delivery, 'revision' => 1,
            'state' => 'in_progress', 'action' => 'started', 'actor_id' => $reviewer->id, 'created_at' => '2026-09-20 10:00:00']);
        if ($state !== 'in_progress') {
            DB::table('aid_delivery_transitions')->insert(['reference' => (string) Str::uuid(), 'delivery_id' => $delivery, 'revision' => 2,
                'state' => $state, 'action' => $state === 'problem' ? 'problem_recorded' : 'simulated_delivered', 'actor_id' => $reviewer->id,
                'note' => $state === 'problem' ? encrypt('PRIVATE-NOTE-CANARY') : null, 'created_at' => '2026-09-20 11:00:00']);
        }
        if ($state === 'simulated_delivered') {
            DB::table('aid_delivery_proofs')->insert(['reference' => (string) Str::uuid(), 'delivery_id' => $delivery, 'sandbox_reference' => str_repeat('a', 64),
                'generator' => 'academic-sandbox', 'version' => 1, 'created_at' => '2026-09-20 11:00:00']);
        }

        return [$campaign, $application, $coordination, $delivery, $owner, $reviewer];
    }

    public static function deliveryStates(): array
    {
        return [['in_progress', '0.00', 0], ['problem', '0.00', 0], ['simulated_delivered', '4.00', 1]];
    }

    #[DataProvider('deliveryStates')]
    public function test_delivery_aggregates_and_private_access_stay_separate(string $state, string $amount, int $count): void
    {
        $actor = $this->admin();
        [$c,$a,$coord,$delivery] = $this->deliveryFixture($state);
        $r = $this->report();
        $this->assertSame($amount, $r->delivery['amount']);
        $this->assertSame($count, $r->delivery['count']);
        $this->assertSame($count === 1 ? '6.00' : '10.00', $r->undelivered);
        $this->assertSame([], $r->warnings);
        $reference = DB::table('assistance_coordinations')->where('id', $coord)->value('reference');
        $this->get('/admin/aid-delivery/'.$a->reference.'/'.$reference)->assertNotFound();
        $this->get('/admin/assistance-coordination/'.$a->reference.'/'.$reference)->assertNotFound();
    }

    public static function corruptDelivery(): array
    {
        return [['proof'], ['proof_time'], ['history_time'], ['history_action'], ['revision'], ['started'], ['confirmed'], ['funded'], ['link'], ['parent'], ['money'], ['exceeds'], ['future'], ['completed_parent']];
    }

    #[DataProvider('corruptDelivery')]
    public function test_corrupt_delivery_never_produces_a_trusted_balance(string $kind): void
    {
        $this->admin();
        [$c,$a,$coord,$delivery] = $this->deliveryFixture();
        match ($kind) {
            'proof' => DB::table('aid_delivery_proofs')->where('delivery_id', $delivery)->delete(),
            'proof_time' => DB::table('aid_delivery_proofs')->where('delivery_id', $delivery)->update(['created_at' => '2026-09-20 12:00:00']),
            'history_time' => DB::table('aid_delivery_transitions')->where('delivery_id', $delivery)->where('revision', 2)->update(['created_at' => '2026-09-20 09:00:00']),
            'history_action' => DB::table('aid_delivery_transitions')->where('delivery_id', $delivery)->where('revision', 2)->update(['action' => 'resumed']),
            'revision' => DB::table('aid_deliveries')->where('id', $delivery)->update(['revision' => 3]),
            'started' => DB::table('aid_deliveries')->where('id', $delivery)->update(['started_at' => '2026-09-20 09:00:00']),
            'confirmed' => DB::table('assistance_coordinations')->where('id', $coord)->update(['confirmed_at' => '2026-09-20 12:00:00']),
            'funded' => DB::table('campaigns')->where('id', $c->id)->update(['funded_at' => '2026-09-21 12:00:00']),
            'link' => DB::table('campaigns')->where('id', $c->id)->update(['help_application_id' => null]),
            'parent' => DB::table('help_applications')->where('id', $a->id)->update(['status' => 'approved']),
            'money' => DB::table('aid_deliveries')->where('id', $delivery)->update(['amount' => '4.001']),
            'exceeds' => DB::table('aid_deliveries')->where('id', $delivery)->update(['amount' => '11.00']),
            'future' => DB::table('aid_deliveries')->where('id', $delivery)->update(['completed_at' => '2026-09-23 00:00:00']),
            'completed_parent' => DB::table('campaigns')->where('id', $c->id)->update(['status' => 'completed']),
        };
        $r = $this->report();
        $this->assertNull($r->delivery['amount']);
        $this->assertNull($r->undelivered);
        $this->assertTrue($r->delivery['incomplete']);
        $this->assertNotEmpty($r->warnings);
        $this->assertSame(0, $r->completedAssistance);
    }

    public function test_sequential_deliveries_and_coherent_completion_without_impact(): void
    {
        $this->admin();
        [$c,$a,$coord,$delivery,$owner,$reviewer] = $this->deliveryFixture();
        $next = DB::table('aid_deliveries')->insertGetId(['reference' => (string) Str::uuid(), 'coordination_id' => $coord,
            'amount' => '6.00', 'currency' => 'SDG', 'state' => 'simulated_delivered', 'revision' => 2, 'entry_key' => bin2hex(random_bytes(32)),
            'started_by' => $reviewer->id, 'started_at' => '2026-09-20 12:00:00', 'completed_at' => '2026-09-20 13:00:00']);
        foreach ([[1, 'in_progress', 'started', '12'], [2, 'simulated_delivered', 'simulated_delivered', '13']] as [$revision,$state,$action,$hour]) {
            DB::table('aid_delivery_transitions')->insert(['reference' => (string) Str::uuid(), 'delivery_id' => $next, 'revision' => $revision,
                'state' => $state, 'action' => $action, 'actor_id' => $reviewer->id, 'created_at' => '2026-09-20 '.$hour.':00:00']);
        }
        DB::table('aid_delivery_proofs')->insert(['reference' => (string) Str::uuid(), 'delivery_id' => $next, 'sandbox_reference' => str_repeat('b', 64),
            'generator' => 'academic-sandbox', 'version' => 1, 'created_at' => '2026-09-20 13:00:00']);
        DB::table('campaigns')->where('id', $c->id)->update(['status' => 'completed', 'completed_at' => '2026-09-20 14:00:00', 'expires_at' => '2026-09-19 00:00:00', 'deleted_at' => now()]);
        DB::table('help_applications')->where('id', $a->id)->update(['status' => 'completed', 'status_changed_at' => '2026-09-20 14:00:00', 'open_slot' => null]);
        DB::table('categories')->where('id', $c->category_id)->update(['is_active' => false]);
        $r = $this->report();
        $this->assertSame('10.00', $r->delivery['amount']);
        $this->assertSame(2, $r->delivery['count']);
        $this->assertSame('0.00', $r->undelivered);
        $this->assertSame(1, $r->completedAssistance);
        $this->assertSame([], $r->warnings);
        DB::table('help_applications')->where('id', $a->id)->update(['open_slot' => true]);
        $r = $this->report();
        $this->assertSame(0, $r->completedAssistance);
        $this->assertArrayHasKey('incoherent_completion', $r->warnings);
    }

    public function test_query_projection_privacy_no_business_writes_and_atomic_audit_failure(): void
    {
        $this->admin();
        [$c,$a,$coord,$delivery] = $this->deliveryFixture('problem');
        $tables = ['campaigns', 'categories', 'help_applications', 'donations', 'payment_attempts', 'assistance_coordinations',
            'aid_deliveries', 'aid_delivery_transitions', 'aid_delivery_proofs', 'internal_notifications', 'internal_notification_events'];
        $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
        $before = $snapshot();
        DB::enableQueryLog();
        $response = $this->get('/admin/reports')->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach (['PRIVATE-APPLICANT-CANARY', 'PRIVATE-STORY-CANARY', 'PRIVATE-RECEIVING-CANARY', 'PRIVATE-NOTE-CANARY', $a->reference] as $canary) {
            $response->assertDontSee($canary);
            $this->assertStringNotContainsString($canary, json_encode($response->viewData('report')));
            $this->assertStringNotContainsString($canary, json_encode(session()->all()));
        }
        foreach ($queries as $query) {
            $sql = strtolower($query['query']);
            if (! str_starts_with($sql, 'select') || str_contains($sql, '"users"')) {
                continue;
            }
            foreach (['full_name', 'email', 'phone', 'private_story', 'requested_amount', 'reviewed_by', 'delivery_details', 'delivery_method', 'provider_reference', 'entry_key', 'capability_hash', 'sandbox_reference', '"note"', '"reference"'] as $field) {
                $this->assertStringNotContainsString($field, $sql);
            }
            $this->assertStringNotContainsString('select *', $sql);
            $this->assertStringNotContainsString('sum(', $sql);
        }
        $this->assertSame($before, $snapshot());
        $auditCount = AuditLog::count();
        $this->mock(AuditLogger::class)->shouldReceive('log')->once()->andReturnUsing(function () {
            DB::table('audit_logs')->insert(['action' => 'reports.viewed']);
            throw new \RuntimeException('PRIVATE-AUDIT-CANARY');
        });
        $this->get('/admin/reports')->assertStatus(500)->assertDontSee('PRIVATE-AUDIT-CANARY');
        $this->assertSame($auditCount, AuditLog::count());
        $this->assertSame($before, $snapshot());
    }

    public function test_paginated_rows_chunk_boundaries_and_query_count(): void
    {
        $this->admin();
        $category = Category::factory()->create();
        $campaigns = Campaign::factory()->count(27)->create(['category_id' => $category->id]);
        for ($i = 0; $i < 251; $i++) {
            $this->donation($campaigns[$i % 27], '0.01');
        }
        Category::factory()->count(26)->create();
        DB::enableQueryLog();
        $first = $this->report();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $second = $this->report('?campaign_page=2&category_page=2');
        $this->assertCount(25, $first->campaigns);
        $this->assertCount(2, $second->campaigns);
        $this->assertCount(25, $first->categories);
        $this->assertCount(2, $second->categories);
        $this->assertSame($first->lifetime, $second->lifetime);
        $this->assertSame('2.51', $second->lifetime['amount']);
        $this->assertSame($campaigns[25]->id, $second->campaigns[0]['id']);
        $this->assertLessThan(25, count($queries));
        $r = $this->report('?campaign_page=10000&category_page=10000');
        $this->assertSame([], $r->campaigns);
        $this->assertSame('2.51', $r->lifetime['amount']);
    }

    public function test_expiry_equality_is_an_active_subset_only(): void
    {
        $this->admin();
        foreach ([['active', now()], ['active', now()->subSecond()], ['active', now()->addSecond()], ['completed', now()->subDay()], ['cancelled', now()->subDay()]] as [$status,$expiry]) {
            Campaign::factory()->create(['status' => $status, 'expires_at' => $expiry]);
        }
        Campaign::factory()->create(['status' => 'active', 'expires_at' => now()->subDay(), 'deleted_at' => now()]);
        $r = $this->report();
        $this->assertSame(2, $r->expiredActiveCampaigns);
        $this->assertSame(3, $r->campaignStatuses['active']);
    }

    public function test_corrupt_currencies_are_not_silently_excluded(): void
    {
        $this->admin();
        [$c,$a,$coord,$delivery] = $this->deliveryFixture();
        DB::statement('DROP TRIGGER donations_money_update');
        DB::table('donations')->where('campaign_id', $c->id)->update(['currency' => 'USD']);
        $r = $this->report();
        $this->assertSame(1, $r->lifetime['count']);
        $this->assertNull($r->lifetime['amount']);
        $this->assertNull($r->period['amount']);
        $this->assertNull($r->undelivered);
        DB::table('donations')->where('campaign_id', $c->id)->update(['currency' => 'SDG']);
        DB::statement('DROP TRIGGER aid_delivery_money_update');
        DB::statement('PRAGMA ignore_check_constraints = ON');
        try {
            DB::table('aid_deliveries')->where('id', $delivery)->update(['currency' => 'USD']);
        } finally {
            DB::statement('PRAGMA ignore_check_constraints = OFF');
        }
        $r = $this->report();
        $this->assertNull($r->delivery['amount']);
        $this->assertNull($r->undelivered);
    }

    public function test_error_route_surface_and_body_rejection(): void
    {
        $this->admin();
        config(['app.debug' => true]);
        $this->headers($this->get('/admin/reports/PRIVATE-CANARY')->assertNotFound()->assertDontSee('PRIVATE-CANARY'));
        $this->headers($this->post('/admin/reports', ['secret' => 'PRIVATE-CANARY'])->assertStatus(405)->assertDontSee('PRIVATE-CANARY'));
        $this->headers($this->json('GET', '/admin/reports', ['unknown' => 'PRIVATE-CANARY'])->assertStatus(422)->assertDontSee('PRIVATE-CANARY'));
        $this->assertSame(0, AuditLog::where('action', 'reports.viewed')->count());
        $this->call('HEAD', '/admin/reports')->assertOk()->assertContent('');
        $route = app('router')->getRoutes()->getByName('admin.reports.index');
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        foreach (['auth', 'role:admin,super_admin', 'throttle:admin-reports'] as $middleware) {
            $this->assertContains($middleware, $route->gatherMiddleware());
        }
    }

    public function test_missing_funding_link_and_stored_money_corruption_are_safe(): void
    {
        $this->admin();
        $c = Campaign::factory()->create();
        $this->donation($c);
        DB::table('campaigns')->where('id', $c->id)->update(['target_amount' => 'PRIVATE-CANARY', 'raised_amount' => '-1.00']);
        $r = $this->report();
        $this->assertSame('0.10', $r->lifetime['amount']);
        $this->assertNull($r->campaigns[0]['stored']);
        $this->assertNull($r->campaigns[0]['target']);
        $this->assertNull($r->campaigns[0]['difference']);
        // Corrupt fixtures bypass referential validation only inside the isolated SQLite test transaction.
        DB::statement('PRAGMA defer_foreign_keys = ON');
        DB::table('donations')->update(['campaign_id' => 999999]);
        $r = $this->report();
        $this->assertSame('0.10', $r->lifetime['amount']);
        $this->assertNull($r->period['amount']);
        $this->assertArrayHasKey('missing_funding_link', $r->warnings);
    }

    public function test_payment_attempts_are_never_queried_or_added(): void
    {
        $this->admin();
        $c = Campaign::factory()->create(['raised_amount' => '0.10']);
        $id = $this->donation($c);
        DB::table('payment_attempts')->insert(['reference' => (string) Str::uuid(), 'donation_id' => $id, 'provider' => 'sandbox',
            'provider_reference' => (string) Str::uuid(), 'status' => 'failed', 'completed_at' => now()]);
        DB::enableQueryLog();
        $r = $this->report();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame(['count' => 1, 'amount' => '0.10'], $r->lifetime);
        foreach ($queries as $query) {
            $this->assertStringNotContainsString('payment_attempts', $query['query']);
        }
    }

    public function test_cancelled_audit_insert_withholds_report(): void
    {
        $this->admin();
        AuditLog::saving(fn () => false);
        $this->headers($this->get('/admin/reports')->assertStatus(500));
        $this->assertDatabaseMissing('audit_logs', ['action' => 'reports.viewed']);
    }

    private function completedProblemResumeFixture(): array
    {
        [$campaign, $application, $coordination, $first, $owner, $reviewer] = $this->deliveryFixture();
        DB::table('campaigns')->where('id', $campaign->id)->update([
            'target_amount' => '2500.00', 'raised_amount' => '2500.00',
            'status' => 'completed', 'completed_at' => '2026-09-20 13:01:00',
        ]);
        DB::table('help_applications')->where('id', $application->id)->update([
            'status' => 'completed', 'status_changed_at' => '2026-09-20 13:01:00', 'open_slot' => null,
        ]);
        DB::table('donations')->where('campaign_id', $campaign->id)->update(['amount' => '1000.00']);
        $this->donation($campaign, '1500.00', paid: '2026-09-19 11:00:00');
        DB::table('aid_deliveries')->where('id', $first)->update(['amount' => '1000.00', 'revision' => 4]);
        DB::table('aid_delivery_transitions')->where('delivery_id', $first)->where('revision', 2)->update(['revision' => 4]);
        foreach ([[2, 'problem', 'problem_recorded', '10:20:00'], [3, 'in_progress', 'resumed', '10:40:00']] as [$revision, $state, $action, $time]) {
            DB::table('aid_delivery_transitions')->insert([
                'reference' => (string) Str::uuid(), 'delivery_id' => $first, 'revision' => $revision,
                'state' => $state, 'action' => $action, 'actor_id' => $reviewer->id,
                'note' => $state === 'problem' ? encrypt('PRIVATE-RESUMED-PROBLEM-CANARY') : null,
                'created_at' => '2026-09-20 '.$time,
            ]);
        }
        $second = DB::table('aid_deliveries')->insertGetId([
            'reference' => (string) Str::uuid(), 'coordination_id' => $coordination, 'amount' => '1500.00',
            'currency' => 'SDG', 'state' => 'simulated_delivered', 'revision' => 2,
            'entry_key' => bin2hex(random_bytes(32)), 'started_by' => $reviewer->id,
            'started_at' => '2026-09-20 12:00:00', 'completed_at' => '2026-09-20 13:00:00',
        ]);
        foreach ([[1, 'in_progress', 'started', '12:00:00'], [2, 'simulated_delivered', 'simulated_delivered', '13:00:00']] as [$revision, $state, $action, $time]) {
            DB::table('aid_delivery_transitions')->insert([
                'reference' => (string) Str::uuid(), 'delivery_id' => $second, 'revision' => $revision,
                'state' => $state, 'action' => $action, 'actor_id' => $reviewer->id, 'created_at' => '2026-09-20 '.$time,
            ]);
        }
        DB::table('aid_delivery_proofs')->insert([
            'reference' => (string) Str::uuid(), 'delivery_id' => $second, 'sandbox_reference' => str_repeat('b', 64),
            'generator' => 'academic-sandbox', 'version' => 1, 'created_at' => '2026-09-20 13:00:00',
        ]);

        return [$first, $second];
    }

    public function test_completed_problem_resume_history_and_second_instalment_reconcile(): void
    {
        $this->admin();
        [$first, $second] = $this->completedProblemResumeFixture();
        $response = $this->get('/admin/reports')->assertOk()->assertDontSee('PRIVATE-RESUMED-PROBLEM-CANARY');
        $report = $response->viewData('report');
        $this->assertSame('2500.00', $report->delivery['amount']);
        $this->assertSame(2, $report->delivery['count']);
        $this->assertSame('0.00', $report->undelivered);
        $this->assertSame(1, $report->completedAssistance);
        $this->assertSame([], $report->warnings);
        $this->assertSame([4, 2], DB::table('aid_deliveries')->whereIn('id', [$first, $second])->orderBy('id')->pluck('revision')->all());
        $this->assertSame(6, DB::table('aid_delivery_transitions')->count());
        $this->assertSame(2, DB::table('aid_delivery_proofs')->count());
    }

    public function test_invalid_first_delivery_does_not_cascade_into_later_valid_delivery(): void
    {
        $this->admin();
        [$first] = $this->completedProblemResumeFixture();
        DB::table('aid_delivery_proofs')->where('delivery_id', $first)->delete();
        $report = $this->report();
        $this->assertSame(1, $report->warnings['invalid_delivery']);
        $this->assertSame(1, $report->delivery['count']);
        $this->assertTrue($report->delivery['incomplete']);
        $this->assertNull($report->delivery['amount']);
        $this->assertNull($report->undelivered);
        $this->assertSame(0, $report->completedAssistance);
        $this->assertSame(1, $report->warnings['incoherent_completion']);
    }

    public static function reportConnectionDrivers(): array
    {
        return [['mysql'], ['mariadb']];
    }

    #[DataProvider('reportConnectionDrivers')]
    public function test_report_leaves_session_timezone_untouched_in_operational_connection_branch(string $driver): void
    {
        $actor = $this->admin();
        $this->completedProblemResumeFixture();
        $manager = DB::getFacadeRoot();
        $sqlite = $manager->connection();
        // No operational PDO is opened. Execute all data queries on the isolated SQLite database.
        // The only permitted connection statement is isolation: timezone SET/restore/read calls fail this contract.
        $connection = \Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->once()->andReturn($driver);
        $connection->shouldReceive('transactionLevel')->once()->andReturn(0);
        $connection->shouldReceive('statement')->once()->with('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')->andReturn(true);
        $connection->shouldReceive('transaction')->once()->andReturnUsing(fn ($callback) => $sqlite->transaction($callback));
        DB::shouldReceive('connection')->once()->andReturn($connection);
        DB::shouldReceive('table')->andReturnUsing(fn ($table) => $sqlite->table($table));
        try {
            $report = app(AdministrativeReportQuery::class)->generate($actor, [
                'start_date' => '2026-09-01', 'end_date' => '2026-09-22', 'group_by' => 'day',
                'campaign_page' => 1, 'category_page' => 1,
            ]);
            $this->assertSame('2500.00', $report->delivery['amount']);
            $this->assertSame(1, $report->completedAssistance);
            $this->assertSame([], $report->warnings);
        } finally {
            DB::swap($manager);
        }
    }
}
