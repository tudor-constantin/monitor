<?php

use App\Actions\Monitors\DispatchDueMonitorChecks;
use App\Jobs\CheckMonitor;
use App\Models\Monitor;
use Illuminate\Support\Facades\Queue;

test('a check dispatched by the scheduler gets a timeout that matches its real HTTP budget', function () {
    Queue::fake();

    $monitor = Monitor::factory()->due()->create([
        'timeout_seconds' => 60,
        'interval_seconds' => 300,
    ]);

    app(DispatchDueMonitorChecks::class)->handle();

    Queue::assertPushed(function (CheckMonitor $job) use ($monitor): bool {
        // The scheduler loads due monitors with a partial column list. If the
        // job reads timeout_seconds off that partial model it silently gets
        // null, and null + 15 is 15 — a queue timeout far below the HTTP budget
        // the check will actually use once the model is rehydrated in handle().
        return $job->monitor->is($monitor)
            && $job->timeout >= $monitor->timeout_seconds;
    });
});

test('the queue timeout always outlasts the HTTP budget of the check it runs', function (int $timeoutSeconds) {
    Queue::fake();

    $monitor = Monitor::factory()->due()->create(['timeout_seconds' => $timeoutSeconds]);

    app(DispatchDueMonitorChecks::class)->handle();

    Queue::assertPushed(
        fn (CheckMonitor $job): bool => $job->timeout > $timeoutSeconds,
    );
})->with([
    'minimum' => 1,
    'default' => 10,
    'maximum' => 60,
]);

test('a check falls back to the configured ceiling when it cannot read the real timeout', function () {
    config(['monitoring.max_timeout_seconds' => 45]);

    $monitor = Monitor::factory()->create(['timeout_seconds' => 30]);
    $partial = Monitor::query()->select(['id', 'is_active'])->findOrFail($monitor->id);

    // Assuming the ceiling costs a worker slot; assuming zero loses the check.
    expect((new CheckMonitor($partial))->timeout)->toBeGreaterThan(45);
});

test('a monitor stored above a since lowered ceiling still gets a queue timeout that covers it', function () {
    Queue::fake();

    // Stored before the operator lowered the ceiling.
    $monitor = Monitor::factory()->due()->create(['timeout_seconds' => 60]);
    config(['monitoring.max_timeout_seconds' => 20]);

    app(DispatchDueMonitorChecks::class)->handle();

    Queue::assertPushed(function (CheckMonitor $job): bool {
        // MonitorChecker clamps its HTTP budget to the same ceiling, so the
        // invariant holds from both directions rather than by coincidence.
        return $job->timeout > config('monitoring.max_timeout_seconds');
    });
});

test('the unique lock outlives the retry budget even for a scheduler dispatched check', function () {
    Queue::fake();

    $monitor = Monitor::factory()->due()->create(['timeout_seconds' => 60]);

    app(DispatchDueMonitorChecks::class)->handle();

    Queue::assertPushed(function (CheckMonitor $job) use ($monitor): bool {
        // uniqueFor() is derived from $this->timeout, so an under-computed
        // timeout would also shorten the lock and let a second check start
        // while the first is still retrying.
        return $job->uniqueFor() >= $job->tries * ($monitor->timeout_seconds + 1);
    });
});
