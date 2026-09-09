<?php

namespace App\Data;

final readonly class ResolvedHelpApplicationDuplicateWarning
{
    public function __construct(public bool $changed) {}
}
