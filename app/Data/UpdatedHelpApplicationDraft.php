<?php

namespace App\Data;

use App\Models\HelpApplication;

final readonly class UpdatedHelpApplicationDraft
{
    public function __construct(
        public HelpApplication $application,
        public bool $changed,
    ) {}
}
