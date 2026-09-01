<?php

namespace App\Enums;

enum InternalNotificationType: string
{
    case HelpApplicationSubmissionConfirmation = 'help_application_submission_confirmation';
    case HelpApplicationNewSubmission = 'help_application_new_submission';
}
