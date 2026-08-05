<?php

declare(strict_types=1);

namespace App\Actions\Monitors;

use App\Models\MonitorCheck;
use Carbon\CarbonInterface;

class PruneOldMonitorChecks
{
    private const BATCH_SIZE = 1000;

    public function handle(CarbonInterface $cutoff): int
    {
        $totalDeleted = 0;

        do {
            $deleted = MonitorCheck::query()
                ->where('checked_at', '<', $cutoff)
                ->limit(self::BATCH_SIZE)
                ->delete();

            $totalDeleted += $deleted;

            if ($deleted === self::BATCH_SIZE) {
                // Each deleted row costs two foreign key checks against
                // incidents. On the first run after months of retention that is
                // millions of rows, so yield briefly between batches instead of
                // holding MySQL flat out for the whole prune.
                usleep(50_000);
            }
        } while ($deleted === self::BATCH_SIZE);

        return $totalDeleted;
    }
}
