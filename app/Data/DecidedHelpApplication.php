<?php

namespace App\Data;

final readonly class DecidedHelpApplication
{
    public function __construct(public string $outcome) {}
}
