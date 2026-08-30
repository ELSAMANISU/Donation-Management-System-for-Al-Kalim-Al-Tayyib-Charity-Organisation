<?php

namespace App\Enums;

enum IdentityDocumentType: string
{
    case NationalId = 'national_id';
    case Passport = 'passport';
}
