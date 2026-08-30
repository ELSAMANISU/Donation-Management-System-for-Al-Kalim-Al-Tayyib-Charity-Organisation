<?php

namespace App\Enums;

enum PublicIdentityPreference: string
{
    case FullName = 'full_name';
    case FirstName = 'first_name';
    case Anonymous = 'anonymous';
}
