<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

final class CampaignApplicationAmount
{
    public const EXCEEDED = 'The target amount must not exceed the requested amount. / يجب ألا يتجاوز المبلغ المستهدف المبلغ المطلوب.';

    public static function canonical(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/\A(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,2})?\z/', $value) !== 1
            || preg_match('/\A0(?:\.0{1,2})?\z/', $value) === 1) {
            return null;
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return $whole.'.'.str_pad($fraction, 2, '0');
    }

    public static function exceeds(string $amount, string $limit): bool
    {
        return strcmp(str_pad($amount, 19, '0', STR_PAD_LEFT), str_pad($limit, 19, '0', STR_PAD_LEFT)) > 0;
    }

    public static function validate(mixed $amount, mixed $limit): string
    {
        $canonical = self::canonical($amount);
        $maximum = self::canonical($limit);
        abort_if($maximum === null, 404);
        if ($canonical === null || self::exceeds($canonical, $maximum)) {
            throw ValidationException::withMessages(['target_amount' => self::EXCEEDED]);
        }

        return $canonical;
    }
}
