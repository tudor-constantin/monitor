<?php

use App\Enums\MonitorCheckStatus;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Services\StatusPages\StatusPageHistoryService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

test('daily status history includes no data, operational, degraded, and outage segments', function () {
    Carbon::setTestNow('2026-07-30 12:00:00');

    $monitor = Monitor::factory()->create();
    MonitorCheck::factory()->for($monitor)->create([
        'status' => MonitorCheckStatus::Successful,
        'checked_at' => now()->subDays(2),
    ]);
    MonitorCheck::factory()->for($monitor)->create([
        'status' => MonitorCheckStatus::Successful,
        'checked_at' => now()->subDay(),
    ]);
    MonitorCheck::factory()->for($monitor)->create([
        'status' => MonitorCheckStatus::Failed,
        'checked_at' => now()->subDay()->addHour(),
    ]);
    MonitorCheck::factory()->for($monitor)->create([
        'status' => MonitorCheckStatus::Failed,
        'checked_at' => now(),
    ]);

    $history = app(StatusPageHistoryService::class)->forMonitors(
        new Collection([$monitor]),
        30,
    );
    $monitorHistory = $history['monitors'][$monitor->id];

    expect($monitorHistory)
        ->total_checks->toBe(4)
        ->uptime_percentage->toBe(50.0)
        ->and(array_column(array_slice($monitorHistory['segments'], -4), 'state'))
        ->toBe(['no-data', 'operational', 'degraded', 'outage']);
});

test('unsupported history periods safely fall back to thirty days', function () {
    $monitor = Monitor::factory()->create();

    $history = app(StatusPageHistoryService::class)->forMonitors(
        new Collection([$monitor]),
        3650,
    );

    expect($history['monitors'][$monitor->id]['segments'])->toHaveCount(30);
});
