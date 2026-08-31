<?php

namespace App\Enums;

enum HelpApplicationDocumentUploaderKind: string
{
    case Applicant = 'applicant';
    case Administrator = 'administrator';
}
