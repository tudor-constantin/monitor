<?php

use App\Actions\Monitors\FindStaleMonitors;
use App\Models\Monitor;
use Illuminate\Support\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

test('an active monitor that stopped being checked is reported as stale', function () {
    Carbon::setTestNow('2026-07-30 12:00:00');

    $healthy = Monitor::factory()->create([
        'interval_seconds' => 300,
        'last_checked_at' => now()->subMinutes(4),
        'next_check_at' => now()->addMinute(),
    ]);
    $stale = Monitor::factory()->create([
        'interval_seconds' => 300,
        'last_checked_at' => now()->subHour(),
        'next_check_at' => now()->addMinute(),
    ]);
    $paused = Monitor::factory()->paused()->create([
        'interval_seconds' => 300,
        'last_checked_at' => now()->subDay(),
    ]);

    $reported = app(FindStaleMonitors::class)->handle();

    expect($reported->pluck('id')->all())->toBe([$stale->id])
        ->and($reported->contains($healthy))->toBeFalse()
        ->and($reported->contains($paused))->toBeFalse();
});

test('an active monitor that was never checked at all is reported once it is overdue', function () {
    Carbon::setTestNow('2026-07-30 12:00:00');

    $neverChecked = Monitor::factory()->create([
        'interval_seconds' => 300,
        'last_checked_at' => null,
        'next_check_at' => now(),
        'created_at' => now()->subHours(3),
    ]);
    $justCreated = Monitor::factory()->create([
        'interval_seconds' => 300,
        'last_checked_at' => null,
        'next_check_at' => now(),
        'created_at' => now()->subSeconds(30),
    ]);

    $reported = app(FindStaleMonitors::class)->handle();

    expect($reported->pluck('id')->all())->toBe([$neverChecked->id])
        ->and($reported->contains($justCreated))->toBeFalse();
});

test('an active monitor left without a next check is still reported', function () {
    Carbon::setTestNow('2026-07-30 12:00:00');

    // is_active with next_check_at null can never be dispatched again, so it is
    // the definition of a silently dead monitor.
    $stranded = Monitor::factory()->create([
        'interval_seconds' => 300,
        'last_checked_at' => now()->subDay(),
        'next_check_at' => null,
    ]);

    expect(app(FindStaleMonitors::class)->handle()->pluck('id')->all())
        ->toBe([$stranded->id]);
});

test('the stale monitor command reports and exits cleanly', function () {
    Carbon::setTestNow('2026-07-30 12:00:00');

    Monitor::factory()->create([
        'name' => 'Quiet website',
        'interval_seconds' => 60,
        'last_checked_at' => now()->subHours(6),
        'next_check_at' => now()->addMinute(),
    ]);

    $this->artisan('monitors:report-stale')
        ->expectsOutputToContain('Quiet website')
        ->assertSuccessful();
});

test('the stale report is bounded so a total outage cannot flood the log', function () {
    Carbon::setTestNow('2026-07-30 12:00:00');

    Monitor::factory()->count(8)->create([
        'interval_seconds' => 60,
        'last_checked_at' => now()->subDay(),
        'next_check_at' => now(),
    ]);

    $finder = app(FindStaleMonitors::class);

    // Every monitor going quiet at once is the normal shape of this failure
    // (Redis away, workers dead), so the sample must stay small while the
    // reported total stays true.
    expect($finder->count())->toBe(8)
        ->and($finder->handle(3))->toHaveCount(3);

    $this->artisan('monitors:report-stale --sample=3')
        ->expectsOutputToContain('8 active monitor(s)')
        ->expectsOutputToContain('Showing 3 of 8')
        ->assertSuccessful();
});
