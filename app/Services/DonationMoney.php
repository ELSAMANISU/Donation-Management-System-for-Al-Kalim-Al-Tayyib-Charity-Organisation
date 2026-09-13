<?php

namespace App\Services;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use Brick\Math\BigDecimal;
use Throwable;

final class DonationMoney
{
    public static function canonical(mixed $amount): bool
    {
        return is_string($amount) && preg_match('/\A(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,2})?\z/', $amount) === 1
            && BigDecimal::of($amount)->isGreaterThan(0);
    }

    public static function remaining(Campaign $campaign): ?string
    {
        try {
            foreach ([$campaign->target_amount, $campaign->raised_amount] as $value) {
                if (! is_string($value) || preg_match('/\A(?:0|[1-9][0-9]{0,15})\.[0-9]{2}\z/', $value) !== 1) {
                    return null;
                }
            }
            $remaining = BigDecimal::of($campaign->target_amount)->minus($campaign->raised_amount);

            return $remaining->isGreaterThan(0) ? (string) $remaining->toScale(2) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function eligible(Campaign $campaign): bool
    {
        return $campaign->status === CampaignStatus::Active && self::remaining($campaign) !== null;
    }
}
