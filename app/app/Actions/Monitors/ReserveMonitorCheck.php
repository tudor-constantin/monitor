<?php

declare(strict_types=1);

namespace App\Actions\Monitors;

use App\Jobs\CheckMonitor;
use App\Models\Monitor;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class ReserveMonitorCheck
{
    public function handle(Monitor $monitor, CarbonInterface $reservedAt): bool
    {
        return DB::transaction(function () use ($monitor, $reservedAt): bool {
            $nextCheckAt = $reservedAt->copy()->addSeconds($monitor->interval_seconds);

            $reserved = Monitor::query()
                ->whereKey($monitor->getKey())
                ->where('is_active', true)
                ->where('next_check_at', $monitor->next_check_at)
                ->where('next_check_at', '<=', $reservedAt)
                ->update(['next_check_at' => $nextCheckAt]);

            if ($reserved !== 1) {
                return false;
            }

            // The slot being reserved, captured before next_check_at moves on.
            // Every copy of this dispatch -- retries, and any copy the unique
            // lock failed to keep out -- carries it, which is what lets the
            // write guard tell them apart from a genuinely later check.
            CheckMonitor::dispatch($monitor, $monitor->next_check_at)->afterCommit();

            return true;
        });
    }
}
