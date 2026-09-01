<?php

namespace App\Console\Commands;

use App\Services\InternalNotificationProjector;
use Illuminate\Console\Command;
use Throwable;

class ProjectPendingInternalNotifications extends Command
{
    protected $signature = 'internal-notifications:project-pending {--limit= : Maximum ready intents to process}';

    protected $description = 'Project pending private internal-notification intents.';

    public function handle(InternalNotificationProjector $projector): int
    {
        try {
            $rawLimit = $this->option('limit');
            $limit = $rawLimit === null ? null : filter_var($rawLimit, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if ($rawLimit !== null && $limit === false) {
                throw new \InvalidArgumentException;
            }

            $result = $projector->projectReady($limit);
            $this->line('projected: '.$result->projected);
            $this->line('cancelled: '.$result->cancelled);
            $this->line('failed: '.$result->failed);
            $this->line('remaining: '.$result->remaining);

            return $result->failed > 0 ? self::FAILURE : self::SUCCESS;
        } catch (Throwable) {
            $this->error('Internal notification projection could not run.');

            return self::FAILURE;
        }
    }
}
