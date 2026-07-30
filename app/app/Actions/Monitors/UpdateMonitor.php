<?php

namespace App\Actions\Monitors;

use App\Enums\MonitorStatus;
use App\Jobs\FetchMonitorFavicon;
use App\Models\Monitor;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class UpdateMonitor
{
    public function __construct(private NormalizeMonitorUrl $normalizeMonitorUrl) {}

    /**
     * @param  array{name: string, url: string, expected_status_code: int, interval_seconds: int, timeout_seconds: int}  $attributes
     */
    public function handle(Monitor $monitor, array $attributes): Monitor
    {
        $url = $this->normalizeMonitorUrl->handle($attributes['url']);
        $urlChanged = $monitor->url !== $url;

        if ($urlChanged && Monitor::query()
            ->where('user_id', $monitor->user_id)
            ->whereKeyNot($monitor->getKey())
            ->where('url', $url)
            ->exists()) {
            throw ValidationException::withMessages([
                'url' => __('This website is already being monitored.'),
            ]);
        }

        if ($urlChanged && $monitor->favicon_path !== null) {
            Storage::disk('public')->delete($monitor->favicon_path);
        }

        $monitor->update([
            ...$attributes,
            'url' => $url,
            ...($urlChanged ? [
                'status' => $monitor->is_active ? MonitorStatus::Pending : MonitorStatus::Paused,
                'consecutive_failures' => 0,
                'last_checked_at' => null,
                'next_check_at' => $monitor->is_active ? now() : null,
            ] : []),
        ]);

        if ($urlChanged) {
            $monitor->forceFill([
                'favicon_path' => null,
                'favicon_fetched_at' => null,
            ])->save();
        }

        $monitor = $monitor->refresh();

        if ($urlChanged) {
            FetchMonitorFavicon::dispatch($monitor)->afterCommit();
        }

        return $monitor;
    }
}
