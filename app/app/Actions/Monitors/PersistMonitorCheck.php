<?php

namespace App\Actions\Monitors;

use App\Data\MonitorCheckResult;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Services\Monitoring\MonitorStatusManager;
use Illuminate\Support\Facades\DB;

class PersistMonitorCheck
{
    public function __construct(
        private readonly MonitorStatusManager $monitorStatusManager,
    ) {}

    public function handle(Monitor $monitor, MonitorCheckResult $result): MonitorCheck
    {
        return DB::transaction(function () use ($monitor, $result): MonitorCheck {
            $lockedMonitor = Monitor::query()
                ->lockForUpdate()
                ->findOrFail($monitor->id);

            $check = $lockedMonitor->checks()->create([
                'status' => $result->status,
                'status_code' => $result->statusCode,
                'response_time_ms' => $result->responseTimeMs,
                'response_size_bytes' => $result->responseSizeBytes,
                'resolved_ip' => $result->resolvedIp,
                'error_type' => $result->errorType,
                'error_message' => $result->errorMessage,
                'checked_at' => $result->checkedAt,
            ]);

            $this->monitorStatusManager->handle($lockedMonitor, $check);

            return $check;
        }, attempts: 3);
    }
}
