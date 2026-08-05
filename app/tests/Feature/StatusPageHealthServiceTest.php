<?php

use App\Enums\MonitorStatus;
use App\Enums\StatusPageHealth;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\User;
use App\Services\StatusPages\StatusPageHealthService;

test('status page health reflects the most severe monitor state', function (
    array $monitorStatuses,
    StatusPageHealth $expectedHealth,
) {
    $user = User::factory()->create();
    $statusPage = StatusPage::factory()->for($user)->create();

    foreach ($monitorStatuses as $position => $status) {
        $monitor = Monitor::factory()->for($user)->create(['status' => $status]);
        $statusPage->monitors()->attach($monitor, ['position' => $position]);
    }

    expect((new StatusPageHealthService)->determineForStatusPage($statusPage->fresh()))
        ->toBe($expectedHealth);
})->with([
    'all operational' => [
        [MonitorStatus::Up, MonitorStatus::Up],
        StatusPageHealth::Operational,
    ],
    'a degraded service' => [
        [MonitorStatus::Up, MonitorStatus::Degraded],
        StatusPageHealth::Degraded,
    ],
    'an outage takes precedence' => [
        [MonitorStatus::Degraded, MonitorStatus::Down],
        StatusPageHealth::Outage,
    ],
    'pending monitoring' => [
        [MonitorStatus::Up, MonitorStatus::Pending],
        StatusPageHealth::Monitoring,
    ],
    'paused monitoring' => [
        [MonitorStatus::Up, MonitorStatus::Paused],
        StatusPageHealth::Monitoring,
    ],
    'no monitors' => [
        [],
        StatusPageHealth::Monitoring,
    ],
]);
