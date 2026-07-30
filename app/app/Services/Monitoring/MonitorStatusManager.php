<?php

namespace App\Services\Monitoring;

use App\Enums\MonitorCheckStatus;
use App\Enums\MonitorStatus;
use App\Models\Monitor;
use App\Models\MonitorCheck;

class MonitorStatusManager
{
    private const FAILURE_THRESHOLD = 2;

    public function __construct(
        private readonly IncidentManager $incidentManager,
    ) {}

    public function handle(Monitor $monitor, MonitorCheck $check): void
    {
        $monitor->last_checked_at = $check->checked_at;

        if ($check->status === MonitorCheckStatus::Successful) {
            $this->recordRecovery($monitor, $check);

            return;
        }

        $this->recordFailure($monitor, $check);
    }

    private function recordRecovery(Monitor $monitor, MonitorCheck $check): void
    {
        $monitor->status = MonitorStatus::Up;
        $monitor->consecutive_failures = 0;
        $monitor->save();

        $this->incidentManager->resolve($monitor, $check);
    }

    private function recordFailure(Monitor $monitor, MonitorCheck $check): void
    {
        $monitor->consecutive_failures++;
        $monitor->status = $monitor->consecutive_failures >= self::FAILURE_THRESHOLD
            ? MonitorStatus::Down
            : MonitorStatus::Degraded;
        $monitor->save();

        if ($monitor->status === MonitorStatus::Down) {
            $this->incidentManager->open(
                $monitor,
                $check,
                $monitor->consecutive_failures,
            );
        }
    }
}
