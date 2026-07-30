<?php

namespace App\Services\Monitoring;

use App\Enums\MonitorCheckStatus;
use App\Events\IncidentOpened;
use App\Events\IncidentResolved;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorCheck;

class IncidentManager
{
    public function open(
        Monitor $monitor,
        MonitorCheck $confirmingCheck,
        int $consecutiveFailures,
    ): Incident {
        $openIncident = $monitor->incidents()
            ->whereNull('resolved_at')
            ->lockForUpdate()
            ->first();

        if ($openIncident !== null) {
            return $openIncident;
        }

        $initialCheck = $monitor->checks()
            ->where('id', '<=', $confirmingCheck->id)
            ->where('status', '!=', MonitorCheckStatus::Successful)
            ->latest('checked_at')
            ->latest('id')
            ->offset(max(0, $consecutiveFailures - 1))
            ->first() ?? $confirmingCheck;

        $incident = $monitor->incidents()->create([
            'started_at' => $initialCheck->checked_at,
            'initial_check_id' => $initialCheck->id,
            'cause' => $confirmingCheck->error_message
                ?? $confirmingCheck->error_type
                ?? 'Health check failed.',
        ]);

        IncidentOpened::dispatch($incident);

        return $incident;
    }

    public function resolve(Monitor $monitor, MonitorCheck $recoveryCheck): ?Incident
    {
        $incident = $monitor->incidents()
            ->whereNull('resolved_at')
            ->lockForUpdate()
            ->first();

        if ($incident === null) {
            return null;
        }

        $incident->update([
            'resolved_at' => $recoveryCheck->checked_at,
            'recovery_check_id' => $recoveryCheck->id,
            'duration_seconds' => max(
                0,
                (int) $incident->started_at->diffInSeconds($recoveryCheck->checked_at),
            ),
        ]);

        IncidentResolved::dispatch($incident);

        return $incident;
    }
}
