<?php

declare(strict_types=1);

namespace App\Services\StatusPages;

use App\Enums\MonitorStatus;
use App\Enums\StatusPageHealth;
use App\Models\Monitor;
use App\Models\StatusPage;
use Illuminate\Support\Collection;

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

    /**
     * @param  Collection<int, Monitor>  $monitors
     */
    public function determine(Collection $monitors): StatusPageHealth
    {
        if ($monitors->contains(fn (Monitor $monitor): bool => $monitor->status === MonitorStatus::Down)) {
            return StatusPageHealth::Outage;
        }

        if ($monitors->contains(fn (Monitor $monitor): bool => $monitor->status === MonitorStatus::Degraded)) {
            return StatusPageHealth::Degraded;
        }

        if (
            $monitors->isEmpty()
            || $monitors->contains(
                fn (Monitor $monitor): bool => in_array(
                    $monitor->status,
                    [MonitorStatus::Pending, MonitorStatus::Paused],
                    true,
                ),
            )
        ) {
            return StatusPageHealth::Monitoring;
        }

        return StatusPageHealth::Operational;
    }
}
