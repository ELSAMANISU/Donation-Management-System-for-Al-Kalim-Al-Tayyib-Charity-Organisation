<?php

namespace App\Services;

use App\Data\AdministrativeReport;
use App\Enums\AidDeliveryAction;
use App\Enums\CampaignStatus;
use App\Enums\DonationStatus;
use App\Enums\HelpApplicationStatus;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

final class AdministrativeReportQuery
{
    private const CHUNK = 250;

    public function __construct(private readonly AuditLogger $audit) {}

    public function generate(User $actor, array $filters): AdministrativeReport
    {
        Gate::forUser($actor)->authorize('viewReports');
        $connection = DB::connection();
        // A transaction alone is insufficient on connections configured READ COMMITTED.
        // SET TRANSACTION applies to this transaction only, never changes business data.
        if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            if ($connection->transactionLevel() !== 0) {
                throw new \RuntimeException('Report snapshot unavailable.');
            }
            // Preserve the writer connection convention for mixed TIMESTAMP/DATETIME fields.
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }

        return $connection->transaction(function () use ($actor, $filters): AdministrativeReport {
            $report = $this->read($filters, CarbonImmutable::now('UTC')->startOfSecond());
            // Audit is the sole write and shares rollback with this read snapshot.
            $entry = $this->audit->log('reports.viewed', actor: $actor);
            if (! $entry->exists || ! $entry->wasRecentlyCreated) {
                throw new \RuntimeException('Report audit unavailable.');
            }

            return $report;
        });
    }

    private function counts(string $table, string $enum, bool $excludeArchived = false): array
    {
        $counts = array_fill_keys(array_column($enum::cases(), 'value'), 0);
        $counts['unrecognized'] = 0;
        $query = DB::table($table)->select('status')->selectRaw('COUNT(*) AS aggregate')->groupBy('status');
        if ($excludeArchived) {
            $query->whereNull('deleted_at');
        }
        foreach ($query->get() as $row) {
            $key = array_key_exists($row->status, $counts) ? $row->status : 'unrecognized';
            $counts[$key] += (int) $row->aggregate;
        }

        return $counts;
    }

    private function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/', $value) !== 1) {
            return null;
        }
        try {
            $time = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $value, 'UTC');

            return $time->format('Y-m-d H:i:s') === $value ? $time : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function read(array $filters, CarbonImmutable $at): AdministrativeReport
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $filters['start_date'], 'UTC');
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $filters['end_date'], 'UTC')->addDay();
        $warnings = [];
        $warn = static function (string $key) use (&$warnings): void {
            $warnings[$key] = ($warnings[$key] ?? 0) + 1;
        };
        $lifetime = ['count' => 0, 'amount' => '0.00'];
        $period = ['count' => 0, 'amount' => '0.00', 'incomplete' => false];
        $buckets = [];
        for ($day = $start; $day->lt($end); $day = $day->addDay()) {
            $key = $day->format($filters['group_by'] === 'month' ? 'Y-m' : 'Y-m-d');
            $buckets[$key] = ['count' => 0, 'amount' => '0.00'];
        }
        $categories = DB::table('categories')->where('is_active', true)->whereNull('deleted_at');
        $categoryCount = $categories->count();
        $categoryRows = $categories->select(['id', 'name_en', 'name_ar'])->orderBy('display_order')->orderBy('name_en')->orderBy('id')
            ->offset(($filters['category_page'] - 1) * 25)->limit(25)->get();
        $categoryTotals = [];
        foreach ($categoryRows as $row) {
            $categoryTotals[$row->id] = ['name_en' => $row->name_en, 'name_ar' => $row->name_ar, 'count' => 0, 'amount' => '0.00'];
        }
        $archivedCategory = ['count' => 0, 'amount' => '0.00'];
        // Only aggregate money per campaign is retained across chunks, never donation rows.
        $funding = [];
        DB::table('donations as d')->leftJoin('campaigns as c', 'c.id', '=', 'd.campaign_id')
            ->leftJoin('categories as cat', 'cat.id', '=', 'c.category_id')->where('d.status', 'succeeded')
            ->select(['d.id', 'd.campaign_id', 'd.amount', 'd.currency', 'd.paid_at', 'd.completed_at', 'd.created_at',
                'c.id as linked_campaign', 'cat.id as category', 'cat.is_active', 'cat.deleted_at'])
            ->chunkById(self::CHUNK, function ($rows) use (&$lifetime, &$period, &$buckets, &$categoryTotals, &$archivedCategory, &$funding, $warn, $at, $start, $end, $filters): void {
                foreach ($rows as $row) {
                    $amount = ReportMoney::contribution($row->amount, $row->currency);
                    $lifetime['count']++;
                    $lifetime['amount'] = ReportMoney::add($lifetime['amount'], $amount);
                    $funding[$row->campaign_id] = ReportMoney::add(array_key_exists($row->campaign_id, $funding) ? $funding[$row->campaign_id] : '0.00', $amount);
                    if ($amount === null) {
                        $warn('invalid_donation_money');
                    }
                    if ($row->linked_campaign === null || $row->category === null) {
                        $warn('missing_funding_link');
                        $period['incomplete'] = true;
                    }
                    $paid = $this->time($row->paid_at);
                    $created = $this->time($row->created_at);
                    if ($paid === null || $created === null || $paid->gt($at) || $created->gt($paid) || $row->completed_at !== $row->paid_at) {
                        $warn('invalid_payment_time');
                        $period['incomplete'] = true;

                        continue;
                    }
                    if ($paid->lt($start) || $paid->gte($end)) {
                        continue;
                    }
                    $period['count']++;
                    $period['amount'] = ReportMoney::add($period['amount'], $amount);
                    $key = $paid->format($filters['group_by'] === 'month' ? 'Y-m' : 'Y-m-d');
                    $buckets[$key]['count']++;
                    $buckets[$key]['amount'] = ReportMoney::add($buckets[$key]['amount'], $amount);
                    if ($row->category !== null && (! $row->is_active || $row->deleted_at !== null)) {
                        $archivedCategory['count']++;
                        $archivedCategory['amount'] = ReportMoney::add($archivedCategory['amount'], $amount);
                    } elseif (isset($categoryTotals[$row->category])) {
                        $categoryTotals[$row->category]['count']++;
                        $categoryTotals[$row->category]['amount'] = ReportMoney::add($categoryTotals[$row->category]['amount'], $amount);
                    }
                }
            }, 'd.id', 'id');
        if ($period['incomplete']) {
            $period['amount'] = null;
            foreach ($buckets as &$bucket) {
                $bucket['amount'] = null;
            } unset($bucket);
            foreach ($categoryTotals as &$category) {
                $category['amount'] = null;
            } unset($category);
            $archivedCategory['amount'] = null;
        }

        [$delivery, $deliveredByCampaign] = $this->deliveries($at, $funding, $warn);
        $campaignRows = [];
        $campaignCount = DB::table('campaigns')->count();
        $pageIds = DB::table('campaigns')->select('id')->orderBy('id')->offset(($filters['campaign_page'] - 1) * 25)->limit(25)->pluck('id')->all();
        $completed = 0;
        DB::table('campaigns as c')->leftJoin('help_applications as h', 'h.id', '=', 'c.help_application_id')
            ->select(['c.id', 'c.title_en', 'c.title_ar', 'c.status', 'c.deleted_at', 'c.completed_at',
                'h.id as application', 'h.status as application_status', 'h.status_changed_at', 'h.open_slot'])
            ->selectRaw('CAST(c.raised_amount AS CHAR) AS raised_amount, CAST(c.target_amount AS CHAR) AS target_amount')
            ->chunkById(self::CHUNK, function ($rows) use (&$campaignRows, &$completed, &$delivery, $deliveredByCampaign, $funding, $pageIds, $warn, $at): void {
                foreach ($rows as $row) {
                    $ledger = array_key_exists($row->id, $funding) ? $funding[$row->id] : '0.00';
                    $stored = ReportMoney::stored($row->raised_amount);
                    $target = ReportMoney::contribution($row->target_amount, 'SDG');
                    $difference = ReportMoney::subtract($stored, $ledger);
                    if ($stored === null || $target === null) {
                        $warn('invalid_campaign_money');
                    }
                    if ($difference !== null && $difference !== '0.00') {
                        $warn('raised_total_mismatch');
                    }
                    if (in_array($row->id, $pageIds, true)) {
                        $campaignRows[] = ['id' => $row->id, 'name_en' => $row->title_en, 'name_ar' => $row->title_ar,
                            'archived' => $row->deleted_at !== null, 'ledger' => $ledger, 'stored' => $stored, 'target' => $target, 'difference' => $difference];
                    }
                    if ($row->status !== 'completed' && $row->application_status !== 'completed') {
                        continue;
                    }
                    $done = $deliveredByCampaign[$row->id] ?? null;
                    $time = $this->time($row->completed_at);
                    $coherent = $row->status === 'completed' && $row->application_status === 'completed' && $row->open_slot === null
                        && $time !== null && $time->lte($at) && $row->completed_at === $row->status_changed_at
                        && $done !== null && ! $done['invalid'] && $done['unfinished'] === 0 && $done['count'] > 0
                        && $done['last'] !== null && $done['last']->lte($time)
                        && $target !== null && $ledger === $target && $stored === $target && $done['amount'] === $target;
                    if ($coherent) {
                        $completed++;
                    } else {
                        $warn('incoherent_completion');
                        $delivery['amount'] = null;
                        $delivery['incomplete'] = true;
                    }
                }
            }, 'c.id', 'id');
        $orphanCompletions = DB::table('help_applications as h')->where('h.status', 'completed')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('campaigns as c')->whereColumn('c.help_application_id', 'h.id'))->count();
        if ($orphanCompletions > 0) {
            $warnings['incoherent_completion'] = ($warnings['incoherent_completion'] ?? 0) + $orphanCompletions;
        }
        $undelivered = ReportMoney::subtract($lifetime['amount'], $delivery['amount']);
        if ($undelivered !== null && BigDecimal::of($undelivered)->isNegative()) {
            $warn('negative_balance');
            $undelivered = null;
        }
        $donationStatuses = $this->counts('donations', DonationStatus::class);
        $campaignStatuses = $this->counts('campaigns', CampaignStatus::class, true);
        $applicationStatuses = $this->counts('help_applications', HelpApplicationStatus::class);
        if ($donationStatuses['unrecognized'] + $campaignStatuses['unrecognized'] + $applicationStatuses['unrecognized'] > 0) {
            $warn('unknown_status');
        }

        return new AdministrativeReport($at->format('Y-m-d H:i:s').' UTC', $filters, $lifetime, $period, $buckets,
            array_values($categoryTotals), $categoryCount, $archivedCategory, $donationStatuses, $campaignStatuses, $applicationStatuses,
            DB::table('campaigns')->whereNotNull('deleted_at')->count(),
            DB::table('campaigns')->whereNull('deleted_at')->where('status', 'active')->where('expires_at', '<=', $at->format('Y-m-d H:i:s'))->count(),
            $campaignRows, $campaignCount, $delivery, $undelivered, $completed, $warnings);
    }

    private function deliveries(CarbonImmutable $at, array $funding, callable $warn): array
    {
        $total = ['count' => 0, 'amount' => '0.00', 'incomplete' => false];
        $byCampaign = [];
        DB::table('aid_deliveries as d')->leftJoin('assistance_coordinations as a', 'a.id', '=', 'd.coordination_id')
            ->leftJoin('campaigns as c', 'c.id', '=', 'a.campaign_id')->leftJoin('help_applications as h', 'h.id', '=', 'a.help_application_id')
            ->select(['d.id', 'd.amount', 'd.currency', 'd.state', 'd.revision', 'd.started_at', 'd.completed_at', 'a.campaign_id', 'a.confirmed_at',
                'c.status as campaign_status', 'h.status as application_status', 'a.started_at as coordination_started', 'c.funded_at', 'c.aid_delivery_started_at'])
            ->selectRaw("CASE WHEN c.help_application_id = a.help_application_id AND h.id IS NOT NULL AND a.state = 'confirmed' THEN 1 ELSE 0 END AS linked")
            // Proof contents/references are never selected. SQL evaluates existence and timestamp agreement only.
            ->selectRaw('EXISTS (SELECT 1 FROM aid_delivery_proofs p WHERE p.delivery_id = d.id) AS has_proof')
            ->selectRaw('EXISTS (SELECT 1 FROM aid_delivery_proofs p WHERE p.delivery_id = d.id AND p.created_at = d.completed_at) AS proof_matches')
            ->chunkById(self::CHUNK, function ($rows) use (&$total, &$byCampaign, $at, $warn): void {
                $histories = DB::table('aid_delivery_transitions')->select(['delivery_id', 'revision', 'state', 'action', 'created_at'])
                    ->whereIn('delivery_id', $rows->pluck('id'))->orderBy('delivery_id')->orderBy('revision')->get()->groupBy('delivery_id');
                foreach ($rows as $row) {
                    $summary = $byCampaign[$row->campaign_id] ?? ['amount' => '0.00', 'count' => 0, 'observed' => 0, 'unfinished' => 0, 'invalid' => false, 'last' => null];
                    $amount = ReportMoney::contribution($row->amount, $row->currency);
                    $started = $this->time($row->started_at);
                    $finished = $this->time($row->completed_at);
                    $confirmed = $this->time($row->confirmed_at);
                    $coordinationStarted = $this->time($row->coordination_started);
                    $funded = $this->time($row->funded_at);
                    $deliveryStarted = $this->time($row->aid_delivery_started_at);
                    $terminal = $row->state === 'simulated_delivered';
                    $history = $histories->get($row->id, collect());
                    $valid = $amount !== null && $row->linked && $started !== null && $confirmed !== null && $started->gte($confirmed) && $started->lte($at)
                        && $coordinationStarted !== null && $funded !== null && $deliveryStarted !== null
                        && $funded->lte($coordinationStarted) && $coordinationStarted->lte($confirmed)
                        && ($summary['observed'] === 0 ? $started->equalTo($deliveryStarted) : $started->gte($deliveryStarted))
                        && in_array($row->campaign_status, ['aid_delivery', 'completed'], true) && $row->application_status === $row->campaign_status
                        && in_array($row->state, ['in_progress', 'problem', 'simulated_delivered'], true)
                        && $history->count() === $row->revision && $row->revision > 0
                        && ($summary['last'] === null || $started?->gte($summary['last'])) && $summary['unfinished'] === 0;
                    $previousTime = null;
                    $previousState = null;
                    foreach ($history->values() as $index => $transition) {
                        $time = $this->time($transition->created_at);
                        $action = AidDeliveryAction::tryFrom($transition->action);
                        $expected = match ($previousState) {
                            null => ['started'], 'in_progress' => ['problem_recorded', 'simulated_delivered'], 'problem' => ['resumed'], default => [],
                        };
                        $valid = $valid && $transition->revision === $index + 1 && $time !== null && $time->lte($at)
                            && ($previousTime === null ? $transition->created_at === $row->started_at : $time->gte($previousTime))
                            && $action !== null && in_array($action->value, $expected, true) && $action->state()->value === $transition->state;
                        $previousTime = $time;
                        $previousState = $transition->state;
                    }
                    $valid = $valid && $previousState === $row->state;
                    if ($terminal) {
                        $valid = $valid && $finished !== null && $finished->lte($at) && $finished->gte($started) && $previousTime?->equalTo($finished) && $row->proof_matches;
                    } else {
                        $valid = $valid && $row->completed_at === null && ! $row->has_proof;
                        $summary['unfinished']++;
                    }
                    if (! $valid) {
                        $warn('invalid_delivery');
                        $summary['invalid'] = true;
                        $total['incomplete'] = true;
                    } elseif ($terminal) {
                        $summary['amount'] = ReportMoney::add($summary['amount'], $amount);
                        $summary['count']++;
                        $total['amount'] = ReportMoney::add($total['amount'], $amount);
                        $total['count']++;
                    }
                    // chunkById visits every entry in stable ID order, even rejected entries.
                    $summary['observed']++;
                    $summary['last'] = $finished;
                    $byCampaign[$row->campaign_id] = $summary;
                }
            }, 'd.id', 'id');
        foreach ($byCampaign as $id => &$summary) {
            $raised = array_key_exists($id, $funding) ? $funding[$id] : '0.00';
            if ($raised === null || BigDecimal::of($summary['amount'])->isGreaterThan($raised)) {
                $warn('delivery_exceeds_funding');
                $summary['invalid'] = true;
                $total['incomplete'] = true;
            }
        }
        unset($summary);
        if ($total['incomplete']) {
            $total['amount'] = null;
        }

        return [$total, $byCampaign];
    }
}
