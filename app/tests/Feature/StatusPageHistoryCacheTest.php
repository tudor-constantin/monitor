<?php

use App\Enums\MonitorCheckStatus;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Services\StatusPages\StatusPageHistoryService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

test('status page history is served from cache on repeat reads', function () {
    Carbon::setTestNow('2026-07-30 12:00:00');

    $monitor = Monitor::factory()->create();
    MonitorCheck::factory()->for($monitor)->create([
        'status' => MonitorCheckStatus::Successful,
        'checked_at' => now()->subHour(),
    ]);

    $service = app(StatusPageHistoryService::class);
    $monitors = new Collection([$monitor]);

    $first = $service->forMonitors($monitors, 30);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $second = $service->forMonitors($monitors, 30);

    expect($queries)->toBe(0)
        ->and($second)->toBe($first);
});

test('history caching can be disabled for very small installs', function () {
    Carbon::setTestNow('2026-07-30 12:00:00');
    config(['monitoring.status_page_history_cache_seconds' => 0]);

    $monitor = Monitor::factory()->create();
    $service = app(StatusPageHistoryService::class);
    $monitors = new Collection([$monitor]);

    $service->forMonitors($monitors, 30);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $service->forMonitors($monitors, 30);

    expect($queries)->toBeGreaterThan(0);
});
