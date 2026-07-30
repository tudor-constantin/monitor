<?php

use App\Data\MonitorMetrics;

test('monitor metrics expose consistent display values', function () {
    $metrics = new MonitorMetrics(
        totalChecks: 12,
        successfulChecks: 11,
        failedChecks: 1,
        uptimePercentage: 91.666,
        averageResponseTimeMs: 1250,
        minimumResponseTimeMs: 90,
        maximumResponseTimeMs: 2400,
        incidentCount: 1,
        totalDowntimeSeconds: 3725,
    );

    expect($metrics)
        ->uptimeLabel()->toBe('91.67%')
        ->averageResponseTimeLabel()->toBe('1,250 ms')
        ->minimumResponseTimeLabel()->toBe('90 ms')
        ->maximumResponseTimeLabel()->toBe('2,400 ms')
        ->downtimeLabel()->toBe('1h 2m');
});

test('monitor metrics identify periods without check data', function () {
    $metrics = new MonitorMetrics(
        totalChecks: 0,
        successfulChecks: 0,
        failedChecks: 0,
        uptimePercentage: null,
        averageResponseTimeMs: null,
        minimumResponseTimeMs: null,
        maximumResponseTimeMs: null,
        incidentCount: 0,
        totalDowntimeSeconds: 0,
    );

    expect($metrics)
        ->uptimeLabel()->toBe('No data')
        ->averageResponseTimeLabel()->toBe('No data')
        ->downtimeLabel()->toBe('0s');
});
