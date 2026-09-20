<?php

namespace App\Services;

final class AidDeliveryInput
{
    public static function valid(string $action, array $input): bool
    {
        $keys = match ($action) {
            'start' => ['amount', 'currency', 'entry_key'],
            'problem' => ['revision', 'note'],
            'resume', 'success' => ['revision'],
            default => [],
        };
        if ($keys === [] || count($keys) !== count($input) || array_diff(array_keys($input), $keys)) {
            return false;
        }
        if ($action === 'start') {
            return DonationMoney::canonical($input['amount'] ?? null) && ($input['currency'] ?? null) === 'SDG'
                && is_string($input['entry_key'] ?? null) && preg_match('/\A[0-9a-f]{64}\z/', $input['entry_key']) === 1;
        }
        if (! is_string($input['revision'] ?? null) || preg_match('/\A[1-9][0-9]{0,8}\z/', $input['revision']) !== 1) {
            return false;
        }

        // A problem requires 1–2000 UTF-8 characters; all other actions forbid notes.
        return $action !== 'problem' || (is_string($input['note'] ?? null) && mb_check_encoding($input['note'], 'UTF-8')
            && trim($input['note']) !== '' && mb_strlen($input['note']) <= 2000
            && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $input['note']) !== 1);
    }
}
