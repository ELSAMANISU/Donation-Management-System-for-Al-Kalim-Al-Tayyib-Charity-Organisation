<?php

namespace App\Enums;

enum CampaignStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Funded = 'funded';
    case AidDelivery = 'aid_delivery';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
