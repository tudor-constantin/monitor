<?php

use App\Enums\MonitorStatus;
use App\Enums\StatusPageHealth;
use App\Models\Monitor;
use App\Services\StatusPages\StatusPageHealthService;
use Illuminate\Database\Eloquent\Collection;

test('status page health reflects the most severe monitor state', function (
    array $monitorStatuses,
    StatusPageHealth $expectedHealth,
) {
    $monitors = new Collection(
        array_map(
            fn (MonitorStatus $status): Monitor => new Monitor(['status' => $status]),
            $monitorStatuses,
        ),
    );

    expect((new StatusPageHealthService)->determine($monitors))
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
    'no monitors' => [
        [],
        StatusPageHealth::Monitoring,
    ],
]);
