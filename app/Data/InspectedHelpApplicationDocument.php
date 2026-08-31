<?php

namespace App\Data;

final readonly class InspectedHelpApplicationDocument
{
    public function __construct(
        public string $extension,
        public string $mimeType,
        public int $sizeBytes,
        public string $checksum,
    ) {}
}
