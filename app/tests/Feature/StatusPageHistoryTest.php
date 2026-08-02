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

test('history dates remain consecutive across calendar boundaries', function (
    int $days,
    string $now,
    string $expectedStart,
) {
    Carbon::setTestNow($now);

    $monitors = Monitor::factory()->count(2)->create();
    $history = app(StatusPageHistoryService::class)->forMonitors($monitors, $days);
    $expectedDates = collect(range(0, $days - 1))
        ->map(fn (int $offset): string => now()
            ->startOfDay()
            ->subDays($days - 1)
            ->addDays($offset)
            ->toDateString())
        ->all();

    expect($history)
        ->starts_at->toBe(Carbon::parse($expectedStart)->format('M j, Y'))
        ->ends_at->toBe(now()->endOfDay()->format('M j, Y'));

    foreach ($monitors as $monitor) {
        expect(array_column($history['monitors'][$monitor->id]['segments'], 'date'))
            ->toBe($expectedDates);
    }
})->with([
    'thirty days including leap day' => [30, '2024-03-01 12:00:00', '2024-02-01'],
    'ninety days crossing a year' => [90, '2026-01-15 12:00:00', '2025-10-18'],
]);
