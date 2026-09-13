<?php

namespace App\Services;

use App\Contracts\DonationGateway;
use App\Enums\DonationStatus;
use Illuminate\Support\Str;

final class SandboxDonationGateway implements DonationGateway
{
    public function provider(): string
    {
        return 'sandbox';
    }

    public function reference(): string
    {
        return (string) Str::uuid();
    }

    public function outcome(string $action): DonationStatus
    {
        abort_unless(config('donations.driver') === 'sandbox', 404);

        return match ($action) {
            'success' => DonationStatus::Succeeded,
            'failure' => DonationStatus::Failed,
            'cancellation' => DonationStatus::Cancelled,
            default => abort(404),
        };
    }
}
