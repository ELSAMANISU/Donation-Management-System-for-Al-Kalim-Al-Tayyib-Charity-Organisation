<?php

namespace App\Services;

use Brick\Math\BigDecimal;

final class ReportMoney
{
    public static function stored(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/\A(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,2})?\z/', $value) !== 1) {
            return null;
        }

        return (string) BigDecimal::of($value)->toScale(2);
    }

    public static function contribution(mixed $value, mixed $currency): ?string
    {
        return $currency === 'SDG' && DonationMoney::canonical($value) ? self::stored($value) : null;
    }

    public static function add(?string $left, ?string $right): ?string
    {
        return $left === null || $right === null ? null : (string) BigDecimal::of($left)->plus($right)->toScale(2);
    }

    public static function subtract(?string $left, ?string $right): ?string
    {
        return $left === null || $right === null ? null : (string) BigDecimal::of($left)->minus($right)->toScale(2);
    }
}
