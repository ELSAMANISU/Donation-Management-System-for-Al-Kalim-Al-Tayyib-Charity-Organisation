<?php

namespace App\Services;

use App\Enums\AssistanceDeliveryMethod;

final class AssistanceCoordinationInput
{
    public static function fields(string $action): array
    {
        return match ($action) {
            'start' => [], 'respond' => ['revision', 'delivery_method', 'delivery_details'],
            'correct' => ['revision', 'body'], 'confirm' => ['revision'], 'message' => ['revision', 'body'],
            default => ['invalid'],
        };
    }

    public static function text(mixed $value, int $maximum): bool
    {
        return is_string($value) && strlen($value) <= $maximum * 4 && mb_check_encoding($value, 'UTF-8')
            && mb_strlen($value, 'UTF-8') <= $maximum && trim($value) !== '' && ! str_contains($value, "\0");
    }

    public static function valid(string $action, array $input): bool
    {
        if (! in_array($action, ['start', 'respond', 'correct', 'confirm', 'message'], true)
            || array_diff(array_keys($input), self::fields($action)) !== []) {
            return false;
        }
        if ($action === 'start') {
            return $input === [];
        }
        if (! is_string($input['revision'] ?? null) || preg_match('/\A[1-9][0-9]{0,8}\z/', $input['revision']) !== 1) {
            return false;
        }
        if ($action === 'respond') {
            return is_string($input['delivery_method'] ?? null) && AssistanceDeliveryMethod::tryFrom($input['delivery_method']) !== null
                && self::text($input['delivery_details'] ?? null, 2000);
        }
        if ($action === 'message') {
            return self::text($input['body'] ?? null, 2000);
        }
        if ($action === 'correct' && array_key_exists('body', $input) && $input['body'] !== '') {
            return self::text($input['body'], 2000);
        }

        return true;
    }
}
