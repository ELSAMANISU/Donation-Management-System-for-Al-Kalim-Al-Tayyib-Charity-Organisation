<?php

namespace App\Enums;

enum HelpApplicationDuplicateWarningStatus: string
{
    case Unreviewed = 'unreviewed';
    case ConfirmedMatch = 'confirmed_match';
    case Dismissed = 'dismissed';
}
