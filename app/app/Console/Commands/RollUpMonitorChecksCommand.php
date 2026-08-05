<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Monitors\RollUpMonitorChecks;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('monitors:roll-up-checks
    {--days= : How many days back to rebuild}
    {--backfill : Rebuild the whole retention window}')]
#[Description('Aggregate raw monitor checks into daily uptime stats')]
class RollUpMonitorChecksCommand extends Command
{
    /**
     * Days the nightly run rebuilds. Only the most recent days can still
     * change, so re-aggregating the full retention window every night would
     * scan every raw check in it to reproduce numbers that already settled.
     * The margin absorbs late checks and a few missed runs; --backfill repairs
     * anything longer than that.
     */
    private const NIGHTLY_DAYS = 7;

    public function handle(RollUpMonitorChecks $rollUpMonitorChecks): int
    {
        $daysOption = $this->option('days');

        $days = match (true) {
            $daysOption !== null && $daysOption !== '' => (int) $daysOption,
            (bool) $this->option('backfill') => (int) config('monitoring.check_retention_days', 90),
            default => self::NIGHTLY_DAYS,
        };

        if ($days < 1) {
            $this->components->error('The roll-up window must be at least one day.');

            return self::FAILURE;
        }

        $written = $rollUpMonitorChecks->handle(now()->subDays($days), now());

        $this->components->info("Rolled up {$written} monitor/day stat rows.");

        $retentionDays = max(
            $days,
            (int) config('monitoring.daily_stat_retention_days', 730),
        );
        $pruned = $rollUpMonitorChecks->prune(now()->subDays($retentionDays)->startOfDay());

        if ($pruned > 0) {
            $this->components->info("Deleted {$pruned} expired daily stat rows.");
        }

        return self::SUCCESS;
    }
}
