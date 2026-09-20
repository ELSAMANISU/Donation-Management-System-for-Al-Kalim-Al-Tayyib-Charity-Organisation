<?php

namespace App\Enums;

enum AidDeliveryAction: string
{
    case Started = 'started';
    case ProblemRecorded = 'problem_recorded';
    case Resumed = 'resumed';
    case SimulatedDelivered = 'simulated_delivered';

    public function state(): AidDeliveryState
    {
        return match ($this) {
            self::Started, self::Resumed => AidDeliveryState::InProgress,
            self::ProblemRecorded => AidDeliveryState::Problem,
            self::SimulatedDelivered => AidDeliveryState::SimulatedDelivered,
        };
    }
}
