<?php

use App\Enums\MonitorStatus;

test('monitor statuses expose consistent English labels', function () {
    expect(MonitorStatus::Pending->label())->toBe('Pending')
        ->and(MonitorStatus::Up->label())->toBe('Up')
        ->and(MonitorStatus::Degraded->label())->toBe('Degraded')
        ->and(MonitorStatus::Down->label())->toBe('Down')
        ->and(MonitorStatus::Paused->label())->toBe('Paused');
});
