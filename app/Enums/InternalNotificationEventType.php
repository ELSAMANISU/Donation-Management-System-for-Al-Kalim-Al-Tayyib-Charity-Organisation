<?php

namespace App\Enums;

enum InternalNotificationEventType: string
{
    case CampaignFundingCompleted = 'campaign_funding_completed';
    case HelpApplicationCampaignActivated = 'help_application_campaign_activated';
    case HelpApplicationSubmitted = 'help_application_submitted';
    case HelpApplicationApproved = 'help_application_approved';
    case HelpApplicationRejected = 'help_application_rejected';
}
