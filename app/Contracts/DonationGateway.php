<?php

namespace App\Contracts;

use App\Enums\DonationStatus;

interface DonationGateway
{
    public function provider(): string;

    public function reference(): string;

    public function outcome(string $action): DonationStatus;
}
