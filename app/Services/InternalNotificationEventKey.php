<?php

namespace App\Services;

use App\Enums\InternalNotificationEventType;
use InvalidArgumentException;

final class InternalNotificationEventKey
{
    public function make(InternalNotificationEventType $type, int $applicationId): string
    {
        if ($type !== InternalNotificationEventType::HelpApplicationSubmitted || $applicationId < 1) {
            throw new InvalidArgumentException('Internal notification event key input is invalid.');
        }

        return hash('sha256', implode("\0", ['internal_notification', $type->value, (string) $applicationId]));
    }
}
