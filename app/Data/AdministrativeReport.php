<?php

namespace App\Data;

/** Only explicit presentation aggregates belong here; never models or database rows. */
final readonly class AdministrativeReport
{
    public function __construct(
        public string $generatedAt,
        public array $filters,
        public array $lifetime,
        public array $period,
        public array $buckets,
        public array $categories,
        public int $categoryCount,
        public array $archivedCategory,
        public array $donationStatuses,
        public array $campaignStatuses,
        public array $applicationStatuses,
        public int $archivedCampaigns,
        public int $expiredActiveCampaigns,
        public array $campaigns,
        public int $campaignCount,
        public array $delivery,
        public ?string $undelivered,
        public int $completedAssistance,
        public array $warnings,
    ) {}
}
