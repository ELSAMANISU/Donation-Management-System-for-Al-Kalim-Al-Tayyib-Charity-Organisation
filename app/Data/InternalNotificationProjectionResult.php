<?php

namespace App\Data;

final readonly class InternalNotificationProjectionResult
{
    public function __construct(
        public int $projected = 0,
        public int $cancelled = 0,
        public int $failed = 0,
        public int $remaining = 0,
    ) {}
}
