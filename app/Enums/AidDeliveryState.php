<?php

namespace App\Enums;

enum AidDeliveryState: string
{
    case InProgress = 'in_progress';
    case Problem = 'problem';
    case SimulatedDelivered = 'simulated_delivered';

    public function label(): string
    {
        return match ($this) {
            self::InProgress => 'In progress / قيد التنفيذ',
            self::Problem => 'Problem / مشكلة',
            self::SimulatedDelivered => 'Simulated delivered / تم التسليم بالمحاكاة',
        };
    }
}
