<?php

namespace App\Enums;

enum InternalNotificationEventType: string
{
    case HelpApplicationCampaignActivated = 'help_application_campaign_activated';
    case HelpApplicationSubmitted = 'help_application_submitted';
    case HelpApplicationApproved = 'help_application_approved';
    case HelpApplicationRejected = 'help_application_rejected';
}
