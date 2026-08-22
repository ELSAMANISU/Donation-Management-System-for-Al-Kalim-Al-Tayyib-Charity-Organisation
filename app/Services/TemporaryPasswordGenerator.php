<?php

namespace App\Services;

use Illuminate\Support\Str;

class TemporaryPasswordGenerator
{
    public function generate(): string
    {
        return Str::password(length: 24, letters: true, numbers: true, symbols: true, spaces: false);
    }
}
