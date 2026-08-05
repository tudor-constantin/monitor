<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Monitors\PruneOldMonitorChecks;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('monitors:prune-checks {--days= : Override the configured retention period}')]
#[Description('Delete raw monitor checks older than the retention period')]
class PruneMonitorChecks extends Command
{
    public function handle(PruneOldMonitorChecks $pruneOldMonitorChecks): int
    {
        $daysOption = $this->option('days');
        $days = (int) ($daysOption === null || $daysOption === ''
            ? config('monitoring.check_retention_days', 90)
            : $daysOption);

        if ($days < 1) {
            $this->components->error('The retention period must be at least one day.');

            return self::FAILURE;
        }

        $deleted = $pruneOldMonitorChecks->handle(now()->subDays($days));

        $this->components->info("Deleted {$deleted} expired monitor checks.");

        return self::SUCCESS;
    }
}
