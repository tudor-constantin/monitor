<?php

declare(strict_types=1);

namespace App\Actions\Monitors;

use App\Models\MonitorCheck;
use Carbon\CarbonInterface;

class PruneOldMonitorChecks
{
    public function handle(CarbonInterface $cutoff): int
    {
        $totalDeleted = 0;

        do {
            $deleted = MonitorCheck::query()
                ->where('checked_at', '<', $cutoff)
                ->limit(1000)
                ->delete();

            $totalDeleted += $deleted;
        } while ($deleted === 1000);

        return $totalDeleted;
    }
}
