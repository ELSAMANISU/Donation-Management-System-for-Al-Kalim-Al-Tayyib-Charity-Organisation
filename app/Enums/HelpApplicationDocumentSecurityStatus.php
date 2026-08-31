<?php

namespace App\Enums;

enum HelpApplicationDocumentSecurityStatus: string
{
    case Pending = 'pending';
    case AcceptedUnscanned = 'accepted_unscanned';
    case Clean = 'clean';
    case Rejected = 'rejected';
}
