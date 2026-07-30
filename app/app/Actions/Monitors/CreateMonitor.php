<?php

namespace App\Actions\Monitors;

use App\Enums\MonitorStatus;
use App\Jobs\FetchMonitorFavicon;
use App\Models\Monitor;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class CreateMonitor
{
    public function __construct(private NormalizeMonitorUrl $normalizeMonitorUrl) {}

    /**
     * @param  array{name: string, url: string, expected_status_code: int, interval_seconds: int, timeout_seconds: int}  $attributes
     */
    public function handle(User $user, array $attributes): Monitor
    {
        $url = $this->normalizeMonitorUrl->handle($attributes['url']);

        if ($user->monitors()->where('url', $url)->exists()) {
            throw ValidationException::withMessages([
                'url' => __('This website is already being monitored.'),
            ]);
        }

        $limit = max(1, (int) config('monitoring.monitor_creation_limit_per_minute', 10));
        $key = "monitor-creation:{$user->getKey()}";
        $monitor = RateLimiter::attempt(
            $key,
            $limit,
            fn (): Monitor => $user->monitors()->create([
                ...$attributes,
                'url' => $url,
                'method' => 'GET',
                'status' => MonitorStatus::Pending,
                'is_active' => true,
                'consecutive_failures' => 0,
                'next_check_at' => now(),
            ]),
            60,
        );

        if (! $monitor instanceof Monitor) {
            $retryAfter = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'name' => __('Too many websites were added. Try again in :seconds seconds.', [
                    'seconds' => $retryAfter,
                ]),
            ]);
        }

        FetchMonitorFavicon::dispatch($monitor)->afterCommit();

        return $monitor;
    }
}
