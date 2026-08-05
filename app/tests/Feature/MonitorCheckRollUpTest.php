<?php

use App\Actions\Monitors\RollUpMonitorChecks;
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

test('the nightly roll-up only rebuilds recent days, backfill rebuilds the window', function () {
    Carbon::setTestNow('2026-07-30 12:00:00');

    $monitor = Monitor::factory()->create();
    MonitorCheck::factory()->for($monitor)->create([
        'status' => MonitorCheckStatus::Successful,
        'checked_at' => now()->subDays(40),
    ]);
    MonitorCheck::factory()->for($monitor)->create([
        'status' => MonitorCheckStatus::Successful,
        'checked_at' => now()->subDay(),
    ]);

    $this->artisan('monitors:roll-up-checks')->assertSuccessful();

    // Re-aggregating 90 days every night to reproduce numbers that settled
    // weeks ago is work the nightly run should not be doing.
    expect(MonitorCheckDailyStat::query()->count())->toBe(1);

    $this->artisan('monitors:roll-up-checks --backfill')->assertSuccessful();

    expect(MonitorCheckDailyStat::query()->count())->toBe(2);
});

test('checks are rolled up into one row per monitor per day', function () {
    Carbon::setTestNow('2026-07-30 12:00:00');

    $monitor = Monitor::factory()->create();

    MonitorCheck::factory()->for($monitor)->count(3)->create([
        'status' => MonitorCheckStatus::Successful,
        'checked_at' => now()->subDays(5),
    ]);
    MonitorCheck::factory()->for($monitor)->create([
        'status' => MonitorCheckStatus::Failed,
        'checked_at' => now()->subDays(5)->addHour(),
    ]);

    app(RollUpMonitorChecks::class)->handle(now()->subDays(10), now());

    $stat = MonitorCheckDailyStat::query()
        ->where('monitor_id', $monitor->id)
        ->whereDate('date', now()->subDays(5)->toDateString())
        ->sole();

    expect($stat)
        ->total_checks->toBe(4)
        ->successful_checks->toBe(3);
});

test('rolling up twice refreshes the day instead of duplicating it', function () {
    Carbon::setTestNow('2026-07-30 12:00:00');

    $monitor = Monitor::factory()->create();
    MonitorCheck::factory()->for($monitor)->create([
        'status' => MonitorCheckStatus::Successful,
        'checked_at' => now()->subDays(3),
    ]);

    $rollUp = app(RollUpMonitorChecks::class);
    $rollUp->handle(now()->subDays(10), now());

    MonitorCheck::factory()->for($monitor)->create([
        'status' => MonitorCheckStatus::Failed,
        'checked_at' => now()->subDays(3)->addHour(),
    ]);
    $rollUp->handle(now()->subDays(10), now());

    expect(MonitorCheckDailyStat::query()->where('monitor_id', $monitor->id)->count())->toBe(1)
        ->and(MonitorCheckDailyStat::query()->where('monitor_id', $monitor->id)->sole())
        ->total_checks->toBe(2)
        ->successful_checks->toBe(1);
});

test('rolled up history survives the raw checks being pruned', function () {
    Carbon::setTestNow('2026-07-30 12:00:00');
    config(['monitoring.check_retention_days' => 7]);

    $monitor = Monitor::factory()->create();
    MonitorCheck::factory()->for($monitor)->count(2)->create([
        'status' => MonitorCheckStatus::Successful,
        'checked_at' => now()->subDays(20),
    ]);

    app(RollUpMonitorChecks::class)->handle(now()->subDays(30), now());
    MonitorCheck::query()->delete();

    $history = app(StatusPageHistoryService::class)
        ->forMonitors(new Collection([$monitor]), 30);

    expect($history['monitors'][$monitor->id])
        ->total_checks->toBe(2)
        ->uptime_percentage->toBe(100.0);
});
