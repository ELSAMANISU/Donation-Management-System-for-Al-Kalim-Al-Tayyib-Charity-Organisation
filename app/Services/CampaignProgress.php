<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Throwable;

final class CampaignProgress
{
    public static function percentage(string $raised, string $target): int
    {
        try {
            $goal = BigDecimal::of($target);
            $total = BigDecimal::of($raised);
            if ($goal->isLessThanOrEqualTo(0) || $total->isLessThanOrEqualTo(0)) {
                return 0;
            }
            if ($total->isGreaterThanOrEqualTo($goal)) {
                return 100;
            }

            return $total->multipliedBy(100)->dividedBy($goal, 0, RoundingMode::HALF_UP)->toInt();
        } catch (Throwable) {
            return 0;
        }
    }
}
