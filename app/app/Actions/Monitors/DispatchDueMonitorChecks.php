<?php

namespace App\Actions\Monitors;

use App\Models\Monitor;

class DispatchDueMonitorChecks
{
    private const BATCH_SIZE = 100;

    public function __construct(
        private readonly ReserveMonitorCheck $reserveMonitorCheck,
    ) {}

    public function handle(): int
    {
        $dispatchedCount = 0;

        do {
            $dueMonitors = Monitor::query()
                ->where('is_active', true)
                ->whereNotNull('next_check_at')
                ->where('next_check_at', '<=', now())
                ->orderBy('next_check_at')
                ->orderBy('id')
                ->limit(self::BATCH_SIZE)
                ->get(['id', 'interval_seconds', 'next_check_at']);

            foreach ($dueMonitors as $monitor) {
                if ($this->reserveMonitorCheck->handle($monitor, now())) {
                    $dispatchedCount++;
                }
            }
        } while ($dueMonitors->count() === self::BATCH_SIZE);

        return $dispatchedCount;
    }
}
