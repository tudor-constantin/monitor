<?php

declare(strict_types=1);

namespace App\Services\StatusPages;

use App\Enums\MonitorStatus;
use App\Enums\StatusPageHealth;
use App\Models\StatusPage;

class StatusPageHealthService
{
    public function determineForStatusPage(StatusPage $statusPage): StatusPageHealth
    {
        $monitors = $statusPage->monitors();

        if ((clone $monitors)->where('status', MonitorStatus::Down)->exists()) {
            return StatusPageHealth::Outage;
        }

        if ((clone $monitors)->where('status', MonitorStatus::Degraded)->exists()) {
            return StatusPageHealth::Degraded;
        }

        if (
            ! (clone $monitors)->exists()
            || (clone $monitors)
                ->whereIn('status', [MonitorStatus::Pending, MonitorStatus::Paused])
                ->exists()
        ) {
            return StatusPageHealth::Monitoring;
        }

        return StatusPageHealth::Operational;
    }
}
