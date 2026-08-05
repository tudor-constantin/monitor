<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\IncidentResolved;
use App\Notifications\MonitorRecoveredNotification;

class SendMonitorRecoveredNotification
{
    public function handle(IncidentResolved $event): void
    {
        $incident = $event->incident->loadMissing('monitor.user');

        $incident->monitor->user->notify(
            new MonitorRecoveredNotification($incident),
        );
    }
}
