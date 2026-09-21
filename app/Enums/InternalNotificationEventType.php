<?php

namespace App\Enums;

enum InternalNotificationEventType: string
{
    case HelpApplicationCompleted = 'help_application_completed';
    case AidDeliveryStarted = 'aid_delivery_started';
    case AidDeliveryProblemRecorded = 'aid_delivery_problem_recorded';
    case AidDeliveryResumed = 'aid_delivery_resumed';
    case AidDeliverySimulatedDelivered = 'aid_delivery_simulated_delivered';
    case CoordinationStarted = 'coordination_started';
    case CoordinationResponseSubmitted = 'coordination_response_submitted';
    case CoordinationChangesRequested = 'coordination_changes_requested';
    case CoordinationMessageAvailable = 'coordination_message_available';
    case CoordinationConfirmed = 'coordination_confirmed';
    case CampaignFundingCompleted = 'campaign_funding_completed';
    case HelpApplicationCampaignActivated = 'help_application_campaign_activated';
    case HelpApplicationSubmitted = 'help_application_submitted';
    case HelpApplicationApproved = 'help_application_approved';
    case HelpApplicationRejected = 'help_application_rejected';
}
