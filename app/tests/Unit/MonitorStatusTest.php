<?php

use App\Enums\MonitorStatus;

test('monitor statuses expose consistent English labels', function () {
    expect(MonitorStatus::Pending->label())->toBe('Pending')
        ->and(MonitorStatus::Up->label())->toBe('Up')
        ->and(MonitorStatus::Degraded->label())->toBe('Degraded')
        ->and(MonitorStatus::Down->label())->toBe('Down')
        ->and(MonitorStatus::Paused->label())->toBe('Paused');
});

test('monitor statuses expose their dashboard urgency weights', function () {
    expect(MonitorStatus::Down->sortWeight())->toBe(0)
        ->and(MonitorStatus::Degraded->sortWeight())->toBe(1)
        ->and(MonitorStatus::Pending->sortWeight())->toBe(2)
        ->and(MonitorStatus::Up->sortWeight())->toBe(3)
        ->and(MonitorStatus::Paused->sortWeight())->toBe(4);
});
