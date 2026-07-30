<?php

namespace App\Actions\Monitors;

use App\Enums\MonitorStatus;
use App\Models\Monitor;

class ResumeMonitor
{
    public function handle(Monitor $monitor): Monitor
    {
        $monitor->update([
            'status' => MonitorStatus::Pending,
            'is_active' => true,
            'consecutive_failures' => 0,
            'next_check_at' => now(),
        ]);

        return $monitor->refresh();
    }
}
