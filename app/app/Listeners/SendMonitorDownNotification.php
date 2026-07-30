<?php

namespace App\Listeners;

use App\Events\IncidentOpened;
use App\Notifications\MonitorDownNotification;

class SendMonitorDownNotification
{
    public function handle(IncidentOpened $event): void
    {
        $incident = $event->incident->loadMissing('monitor.user');

        $incident->monitor->user->notify(
            new MonitorDownNotification($incident),
        );
    }
}
