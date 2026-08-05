<?php

declare(strict_types=1);

namespace App\Actions\Monitors;

use App\Models\Monitor;
use Illuminate\Support\Facades\Storage;

class DeleteMonitor
{
    public function handle(Monitor $monitor): void
    {
        if ($monitor->favicon_path !== null) {
            Storage::disk('public')->delete($monitor->favicon_path);
        }

        $monitor->delete();
    }
}
