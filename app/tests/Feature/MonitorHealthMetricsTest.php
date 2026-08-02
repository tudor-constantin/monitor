<?php

use App\Enums\MonitorCheckStatus;
use App\Enums\MonitorStatus;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\User;
use App\Services\Monitoring\MonitorMetricsService;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-07-30 12:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

test('metrics calculate uptime latency incidents and overlapping downtime for a bounded period', function () {
    $monitor = Monitor::factory()->create(['status' => MonitorStatus::Up]);

    MonitorCheck::factory()->for($monitor)->create([
        'status' => MonitorCheckStatus::Successful,
        'response_time_ms' => 100,
        'checked_at' => now()->subHour(),
    ]);
    MonitorCheck::factory()->for($monitor)->create([
        'status' => MonitorCheckStatus::Successful,
        'response_time_ms' => 300,
        'checked_at' => now()->subHours(2),
    ]);
    MonitorCheck::factory()->for($monitor)->create([
        'status' => MonitorCheckStatus::Failed,
        'status_code' => 503,
        'response_time_ms' => 500,
        'checked_at' => now()->subHours(3),
    ]);
    MonitorCheck::factory()->for($monitor)->create([
        'status' => MonitorCheckStatus::Successful,
        'response_time_ms' => 999,
        'checked_at' => now()->subHours(25),
    ]);

    Incident::factory()->for($monitor)->create([
        'started_at' => now()->subHours(4),
        'resolved_at' => now()->subHours(3),
        'duration_seconds' => 3600,
    ]);
    Incident::factory()->for($monitor)->create([
        'started_at' => now()->subHours(30),
        'resolved_at' => now()->subHours(23),
        'duration_seconds' => 25200,
    ]);
    Incident::factory()->for($monitor)->create([
        'started_at' => now()->subMinutes(30),
        'resolved_at' => null,
        'duration_seconds' => null,
    ]);

    $metrics = resolve(MonitorMetricsService::class)
        ->forPeriod($monitor, now()->subDay());

    expect($metrics)
        ->totalChecks->toBe(3)
        ->successfulChecks->toBe(2)
        ->failedChecks->toBe(1)
        ->uptimePercentage->toBe(66.67)
        ->averageResponseTimeMs->toBe(300)
        ->minimumResponseTimeMs->toBe(100)
        ->maximumResponseTimeMs->toBe(500)
        ->incidentCount->toBe(3)
        ->totalDowntimeSeconds->toBe(9000);
});

test('response time history is bounded to the requested window and sample limit', function () {
    $monitor = Monitor::factory()->create();

    MonitorCheck::factory()->count(5)->for($monitor)->sequence(
        ['response_time_ms' => 100, 'checked_at' => now()->subMinutes(5)],
        ['response_time_ms' => 200, 'checked_at' => now()->subMinutes(4)],
        ['response_time_ms' => 300, 'checked_at' => now()->subMinutes(3)],
        ['response_time_ms' => 400, 'checked_at' => now()->subMinutes(2)],
        ['response_time_ms' => 500, 'checked_at' => now()->subMinutes()],
    )->create();
    MonitorCheck::factory()->for($monitor)->create([
        'response_time_ms' => 999,
        'checked_at' => now()->subDays(2),
    ]);

    $series = resolve(MonitorMetricsService::class)
        ->responseTimeSeries($monitor, now()->subDay(), limit: 3);

    expect($series)
        ->sampleCount->toBe(3)
        ->minimumResponseTimeMs->toBe(300)
        ->maximumResponseTimeMs->toBe(500)
        ->averageResponseTimeMs->toBe(400)
        ->latestResponseTimeMs->toBe(500)
        ->and($series->points)->not->toBeEmpty();
});

test('monitor details show health metrics and incident history only to the owner', function () {
    $user = User::factory()->create();
    $monitor = Monitor::factory()->for($user)->create([
        'name' => 'Customer portal',
        'status' => MonitorStatus::Up,
        'last_checked_at' => now()->subMinute(),
    ]);
    MonitorCheck::factory()->for($monitor)->create([
        'response_time_ms' => 125,
        'checked_at' => now()->subMinute(),
    ]);
    Incident::factory()->resolved()->for($monitor)->create([
        'started_at' => now()->subHours(2),
        'resolved_at' => now()->subHour(),
        'duration_seconds' => 3600,
    ]);

    Livewire::actingAs($user)
        ->test('pages::monitors.show', ['monitor' => $monitor])
        ->assertSee('Response time · 24 hours')
        ->assertSee('100.00%')
        ->assertSee('About uptime')
        ->assertSee('Uptime is the percentage of recorded checks that completed successfully')
        ->assertSee('125 ms')
        ->assertSee('Incident timeline')
        ->assertSee('Service recovered')
        ->assertSee('Website configuration');
});

test('dashboard monitor cards show health data and support pausing', function () {
    $user = User::factory()->create();
    $monitor = Monitor::factory()->for($user)->create([
        'name' => 'Public API',
        'status' => MonitorStatus::Up,
        'last_checked_at' => now()->subMinute(),
    ]);
    MonitorCheck::factory()->for($monitor)->create([
        'response_time_ms' => 210,
        'checked_at' => now()->subMinute(),
    ]);

    Livewire::actingAs($user)
        ->test('pages::dashboard')
        ->assertSee('Public API')
        ->assertSee('210 ms')
        ->assertSee('100.00%')
        ->call('pause', $monitor->id)
        ->assertHasNoErrors();

    expect($monitor->refresh())
        ->status->toBe(MonitorStatus::Paused)
        ->is_active->toBeFalse();
});
