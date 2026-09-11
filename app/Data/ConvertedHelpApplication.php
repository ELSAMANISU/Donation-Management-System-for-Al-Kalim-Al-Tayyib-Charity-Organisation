<?php

namespace App\Data;

use App\Models\Campaign;

final readonly class ConvertedHelpApplication
{
    public function __construct(public Campaign $campaign) {}
}
