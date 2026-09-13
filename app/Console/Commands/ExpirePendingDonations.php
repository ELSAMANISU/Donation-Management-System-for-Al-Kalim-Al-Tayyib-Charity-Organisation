<?php

namespace App\Console\Commands;

use App\Services\DonationService;
use Illuminate\Console\Command;
use Throwable;

class ExpirePendingDonations extends Command
{
    protected $signature = 'donations:expire {--limit=100 : Maximum expired checkouts to finalize (1–1000)}';

    protected $description = 'Finalize expired sandbox checkouts without changing Campaign funds.';

    public function handle(DonationService $donations): int
    {
        try {
            $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
            if ($limit === false) {
                throw new \InvalidArgumentException;
            }
            $this->line('expired: '.$donations->expireDue($limit));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Sandbox checkout expiry could not run.');

            return self::FAILURE;
        }
    }
}
