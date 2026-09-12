<?php

namespace App\Enums;

enum InternalNotificationType: string
{
    case HelpApplicationCampaignActivated = 'help_application_campaign_activated';
    case HelpApplicationSubmissionConfirmation = 'help_application_submission_confirmation';
    case HelpApplicationNewSubmission = 'help_application_new_submission';
    case HelpApplicationApproved = 'help_application_approved';
    case HelpApplicationRejected = 'help_application_rejected';
}
