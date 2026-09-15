<?php

namespace App\Enums;

enum InternalNotificationType: string
{
    case CoordinationStarted = 'coordination_started';
    case CoordinationResponseSubmitted = 'coordination_response_submitted';
    case CoordinationChangesRequested = 'coordination_changes_requested';
    case CoordinationMessageAvailable = 'coordination_message_available';
    case CoordinationConfirmed = 'coordination_confirmed';
    case CampaignFundingCompleted = 'campaign_funding_completed';
    case HelpApplicationCampaignActivated = 'help_application_campaign_activated';
    case HelpApplicationSubmissionConfirmation = 'help_application_submission_confirmation';
    case HelpApplicationNewSubmission = 'help_application_new_submission';
    case HelpApplicationApproved = 'help_application_approved';
    case HelpApplicationRejected = 'help_application_rejected';
}
