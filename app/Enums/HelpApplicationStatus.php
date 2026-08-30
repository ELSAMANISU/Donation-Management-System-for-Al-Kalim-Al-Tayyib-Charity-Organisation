<?php

namespace App\Enums;

enum HelpApplicationStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case UnderReview = 'under_review';
    case AdditionalInformationRequired = 'additional_information_required';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Appealed = 'appealed';
    case ConvertedToCampaign = 'converted_to_campaign';
    case CampaignActive = 'campaign_active';
    case AidDelivery = 'aid_delivery';
    case Completed = 'completed';
    case Closed = 'closed';

    public function isOpen(): bool
    {
        return ! $this->isTerminal();
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Closed => true,
            default => false,
        };
    }
}
