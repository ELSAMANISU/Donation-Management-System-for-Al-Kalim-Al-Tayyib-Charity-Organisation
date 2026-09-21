<?php

namespace App\Services;

final class CampaignImpactInput
{
    public static function text(mixed $value): ?string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            return null;
        }
        $value = trim(preg_replace('/[ \t]+/u', ' ', str_replace(["\r\n", "\r"], "\n", $value)));
        // Plain synthetic narrative only. Human publication review remains mandatory.
        if ($value === '' || mb_strlen($value) > 2000
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F<>]/u', $value)
            || preg_match('~(?:https?://|www\.|@|[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}|\b(?:password|cvv|iban|account number|card number|wallet|رقم الحساب|كلمة المرور)\b)~iu', $value)
            || preg_match('/\b(?:\+?[0-9][\s().-]?){8,}\b/u', $value)) {
            return null;
        }

        return $value;
    }
}
