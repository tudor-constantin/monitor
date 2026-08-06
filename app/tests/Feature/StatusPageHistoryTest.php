<?php

use App\Actions\Monitors\PruneOldMonitorChecks;
use App\Enums\MonitorCheckStatus;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorCheckDailyStat;
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

    expect(array_slice($monitorHistory['segments'], -4))
        ->sequence(
            fn ($segment) => $segment
                ->date_label->toBe('Mon, Jul 27, 2026')
                ->state_label->toBe('No data')
                ->successful_checks->toBe(0)
                ->total_checks->toBe(0)
                ->uptime_percentage->toBeNull(),
            fn ($segment) => $segment
                ->date_label->toBe('Tue, Jul 28, 2026')
                ->state_label->toBe('No incidents')
                ->successful_checks->toBe(1)
                ->total_checks->toBe(1)
                ->uptime_percentage->toBe(100.0),
            fn ($segment) => $segment
                ->date_label->toBe('Wed, Jul 29, 2026')
                ->state_label->toBe('Degraded')
                ->successful_checks->toBe(1)
                ->total_checks->toBe(2)
                ->uptime_percentage->toBe(50.0),
            fn ($segment) => $segment
                ->date_label->toBe('Thu, Jul 30, 2026')
                ->state_label->toBe('Outage')
                ->successful_checks->toBe(0)
                ->total_checks->toBe(1)
                ->uptime_percentage->toBe(0.0),
        );

    expect($monitorHistory['segments'][array_key_last($monitorHistory['segments'])]['label'])
        ->toBe('Thu, Jul 30, 2026: Outage; 0 of 1 checks successful; 0.00% daily uptime');
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

test('a partially pruned cutoff day does not overwrite the complete daily roll-up', function () {
    // Retention cutoff falls at 2026-07-23 02:00:00: the pruner deletes
    // checked_at < cutoff, which removes the 01:00 check but leaves the 03:00
    // one on the same calendar day, so raw rows for that day are incomplete.
    Carbon::setTestNow('2026-07-30 02:00:00');
    config(['monitoring.check_retention_days' => 7]);

    $monitor = Monitor::factory()->create();

    MonitorCheckDailyStat::query()->create([
        'monitor_id' => $monitor->id,
        'date' => '2026-07-23',
        'total_checks' => 2,
        'successful_checks' => 1,
    ]);

    MonitorCheck::factory()->for($monitor)->create([
        'status' => MonitorCheckStatus::Successful,
        'checked_at' => '2026-07-23 01:00:00',
    ]);
    MonitorCheck::factory()->for($monitor)->create([
        'status' => MonitorCheckStatus::Failed,
        'checked_at' => '2026-07-23 03:00:00',
    ]);

    app(PruneOldMonitorChecks::class)->handle(now()->subDays(7));

    $history = app(StatusPageHistoryService::class)->forMonitors(
        new Collection([$monitor]),
        30,
    );

    $segment = collect($history['monitors'][$monitor->id]['segments'])
        ->firstWhere('date', '2026-07-23');

    expect($segment)
        ->total_checks->toBe(2)
        ->successful_checks->toBe(1)
        ->state->toBe('degraded');
});
