<?php

use App\Actions\Monitors\PersistMonitorCheck;
use App\Data\MonitorCheckResult;
use App\Enums\MonitorCheckStatus;
use App\Enums\MonitorStatus;
use App\Events\IncidentOpened;
use App\Events\IncidentResolved;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;

function persistMonitorResult(
    Monitor $monitor,
    MonitorCheckStatus $status,
    CarbonInterface $checkedAt,
    ?string $errorType = null,
    ?string $errorMessage = null,
): MonitorCheck {
    return app(PersistMonitorCheck::class)->handle(
        $monitor,
        new MonitorCheckResult(
            status: $status,
            statusCode: $status === MonitorCheckStatus::Successful ? 200 : 503,
            responseTimeMs: 120,
            responseSizeBytes: 12,
            resolvedIp: '93.184.216.34',
            errorType: $errorType,
            errorMessage: $errorMessage,
            checkedAt: $checkedAt,
        ),
    );
}

test('a successful first check changes a pending monitor to up', function () {
    $monitor = Monitor::factory()->create();

    persistMonitorResult($monitor, MonitorCheckStatus::Successful, now());

    expect($monitor->refresh())
        ->status->toBe(MonitorStatus::Up)
        ->consecutive_failures->toBe(0)
        ->last_checked_at->not->toBeNull()
        ->and($monitor->incidents()->count())->toBe(0);
});

test('two consecutive failures open one incident and recovery resolves it', function () {
    $this->travelTo(now()->startOfSecond());

    $monitor = Monitor::factory()->create(['status' => MonitorStatus::Up]);
    Event::fake([IncidentOpened::class, IncidentResolved::class]);

    $firstFailureAt = now();
    $firstFailure = persistMonitorResult(
        $monitor,
        MonitorCheckStatus::Failed,
        $firstFailureAt,
        'unexpected_status_code',
        'Expected HTTP 200, received 503.',
    );

    expect($monitor->refresh())
        ->status->toBe(MonitorStatus::Degraded)
        ->consecutive_failures->toBe(1)
        ->and($monitor->incidents()->count())->toBe(0);

    $secondFailure = persistMonitorResult(
        $monitor,
        MonitorCheckStatus::Failed,
        $firstFailureAt->addMinute(),
        'unexpected_status_code',
        'Expected HTTP 200, received 503.',
    );

    $incident = $monitor->incidents()->sole();

    expect($monitor->refresh())
        ->status->toBe(MonitorStatus::Down)
        ->consecutive_failures->toBe(2)
        ->and($incident)
        ->initial_check_id->toBe($firstFailure->id)
        ->resolved_at->toBeNull()
        ->cause->toBe('Expected HTTP 200, received 503.');

    persistMonitorResult(
        $monitor,
        MonitorCheckStatus::Timeout,
        $firstFailureAt->addMinutes(2),
        'timeout',
        'The request timed out.',
    );

    expect($monitor->refresh())
        ->status->toBe(MonitorStatus::Down)
        ->consecutive_failures->toBe(3)
        ->and($monitor->incidents()->count())->toBe(1);

    $recoveryAt = $firstFailureAt->addMinutes(3);
    $recoveryCheck = persistMonitorResult(
        $monitor,
        MonitorCheckStatus::Successful,
        $recoveryAt,
    );

    expect($monitor->refresh())
        ->status->toBe(MonitorStatus::Up)
        ->consecutive_failures->toBe(0)
        ->and($incident->refresh())
        ->recovery_check_id->toBe($recoveryCheck->id)
        ->duration_seconds->toBe(180)
        ->and($incident->resolved_at->equalTo($recoveryAt))->toBeTrue();

    Event::assertDispatched(
        IncidentOpened::class,
        fn (IncidentOpened $event): bool => $event->incident->is($incident),
    );
    Event::assertDispatched(
        IncidentResolved::class,
        fn (IncidentResolved $event): bool => $event->incident->is($incident),
    );
    Event::assertDispatchedTimes(IncidentOpened::class, 1);
    Event::assertDispatchedTimes(IncidentResolved::class, 1);

    expect($secondFailure->status)->toBe(MonitorCheckStatus::Failed);
});

test('the database prevents multiple open incidents for one monitor', function () {
    $monitor = Monitor::factory()->create();

    Incident::factory()->for($monitor)->create();

    expect(fn () => Incident::factory()->for($monitor)->create())
        ->toThrow(QueryException::class);
});
