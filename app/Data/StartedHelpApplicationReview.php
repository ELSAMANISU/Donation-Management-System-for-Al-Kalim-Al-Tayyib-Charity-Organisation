<?php

namespace App\Data;

final readonly class StartedHelpApplicationReview
{
    public function __construct(public bool $changed) {}
}
