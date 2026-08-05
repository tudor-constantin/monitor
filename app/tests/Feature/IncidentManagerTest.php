<?php

use App\Enums\MonitorCheckStatus;
use App\Events\IncidentOpened;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Services\Monitoring\IncidentManager;
use Illuminate\Support\Facades\Event;

test('opening an incident walks back to the first check of the failing streak', function () {
    $monitor = Monitor::factory()->create();

    MonitorCheck::factory()->for($monitor)->create([
        'status' => MonitorCheckStatus::Successful,
        'checked_at' => now()->subMinutes(10),
    ]);

    $firstFailure = MonitorCheck::factory()->for($monitor)->create([
        'status' => MonitorCheckStatus::Failed,
        'checked_at' => now()->subMinutes(3),
    ]);
    MonitorCheck::factory()->for($monitor)->create([
        'status' => MonitorCheckStatus::Failed,
        'checked_at' => now()->subMinutes(2),
    ]);
    $confirmingCheck = MonitorCheck::factory()->for($monitor)->create([
        'status' => MonitorCheckStatus::Failed,
        'checked_at' => now()->subMinute(),
    ]);

    $incident = app(IncidentManager::class)->open($monitor, $confirmingCheck, consecutiveFailures: 3);

    expect($incident)
        ->initial_check_id->toBe($firstFailure->id)
        ->and($incident->started_at->equalTo($firstFailure->checked_at))->toBeTrue();
});

test('opening an incident falls back to the confirming check when history is shorter than the streak', function () {
    $monitor = Monitor::factory()->create();

    MonitorCheck::factory()->for($monitor)->create([
        'status' => MonitorCheckStatus::Failed,
        'checked_at' => now()->subMinutes(2),
    ]);
    $confirmingCheck = MonitorCheck::factory()->for($monitor)->create([
        'status' => MonitorCheckStatus::Failed,
        'checked_at' => now()->subMinute(),
    ]);

    // Only two failed checks exist, but the reported streak is five long
    // (e.g. earlier checks were pruned) — the offset lookup runs out of
    // history and must not throw, it should fall back to the check that
    // confirmed the incident instead.
    $incident = app(IncidentManager::class)->open($monitor, $confirmingCheck, consecutiveFailures: 5);

    expect($incident)->initial_check_id->toBe($confirmingCheck->id);
});

test('opening an incident is idempotent when one is already open for the monitor', function () {
    $monitor = Monitor::factory()->create();
    $existingIncident = Incident::factory()->for($monitor)->create(['resolved_at' => null]);

    Event::fake([IncidentOpened::class]);

    $check = MonitorCheck::factory()->for($monitor)->create(['status' => MonitorCheckStatus::Failed]);
    $incident = app(IncidentManager::class)->open($monitor, $check, consecutiveFailures: 3);

    expect($incident->is($existingIncident))->toBeTrue();
    expect($monitor->incidents()->count())->toBe(1);

    Event::assertNotDispatched(IncidentOpened::class);
});

test('resolving returns null when no incident is open for the monitor', function () {
    $monitor = Monitor::factory()->create();
    $check = MonitorCheck::factory()->for($monitor)->create(['status' => MonitorCheckStatus::Successful]);

    expect(app(IncidentManager::class)->resolve($monitor, $check))->toBeNull();
});
