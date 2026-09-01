<?php

namespace App\Enums;

enum InternalNotificationAudience: string
{
    case Applicant = 'applicant';
    case Administrator = 'administrator';
}
