<?php

declare(strict_types=1);

namespace App\Actions\Monitors;

use App\Enums\MonitorStatus;
use App\Models\Monitor;

class PauseMonitor
{
    public function handle(Monitor $monitor): Monitor
    {
        $monitor->update([
            'status' => MonitorStatus::Paused,
            'is_active' => false,
            'next_check_at' => null,
        ]);

        return $monitor->refresh();
    }
}
