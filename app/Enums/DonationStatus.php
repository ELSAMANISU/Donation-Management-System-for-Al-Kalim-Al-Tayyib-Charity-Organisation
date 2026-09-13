<?php

namespace App\Enums;

enum DonationStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
