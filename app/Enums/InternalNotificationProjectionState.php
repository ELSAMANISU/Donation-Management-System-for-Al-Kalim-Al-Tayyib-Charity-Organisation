<?php

namespace App\Enums;

enum InternalNotificationProjectionState: string
{
    case Pending = 'pending';
    case Projected = 'projected';
    case Cancelled = 'cancelled';
}
