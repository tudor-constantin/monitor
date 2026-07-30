<?php

use App\Actions\Monitors\DispatchDueMonitorChecks;
use App\Actions\Monitors\ReserveMonitorCheck;
use App\Jobs\CheckMonitor;
use App\Models\Monitor;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;

test('due active monitors are reserved and dispatched once per interval', function () {
    Queue::fake();
    $this->travelTo(now()->startOfSecond());

    $monitor = Monitor::factory()->due()->create([
        'interval_seconds' => 300,
    ]);

    $dispatcher = app(DispatchDueMonitorChecks::class);

    expect($dispatcher->handle())->toBe(1)
        ->and($dispatcher->handle())->toBe(0)
        ->and($monitor->refresh()->next_check_at->equalTo(now()->addMinutes(5)))->toBeTrue();

    Queue::assertPushedOn('checks', CheckMonitor::class);
    Queue::assertPushed(CheckMonitor::class, 1);
    Queue::assertPushed(
        CheckMonitor::class,
        fn (CheckMonitor $job): bool => $job->monitor->is($monitor)
            && $job->connection === 'redis',
    );
});

test('stale concurrent claims cannot dispatch the same monitor twice', function () {
    Queue::fake();
    $this->travelTo(now()->startOfSecond());

    $monitor = Monitor::factory()->due()->create();
    $firstClaim = Monitor::query()->findOrFail($monitor->id);
    $staleClaim = Monitor::query()->findOrFail($monitor->id);
    $reservation = app(ReserveMonitorCheck::class);

    expect($reservation->handle($firstClaim, now()))->toBeTrue()
        ->and($reservation->handle($staleClaim, now()))->toBeFalse();

    Queue::assertPushed(CheckMonitor::class, 1);
});

test('each monitor is rescheduled using its configured interval', function () {
    Queue::fake();
    $this->travelTo(now()->startOfSecond());

    $oneMinuteMonitor = Monitor::factory()->due()->create(['interval_seconds' => 60]);
    $tenMinuteMonitor = Monitor::factory()->due()->create(['interval_seconds' => 600]);

    expect(app(DispatchDueMonitorChecks::class)->handle())->toBe(2)
        ->and($oneMinuteMonitor->refresh()->next_check_at->equalTo(now()->addMinute()))->toBeTrue()
        ->and($tenMinuteMonitor->refresh()->next_check_at->equalTo(now()->addMinutes(10)))->toBeTrue();

    Queue::assertPushed(CheckMonitor::class, 2);
});

test('paused and future monitors are not dispatched', function () {
    Queue::fake();

    Monitor::factory()->paused()->create();
    Monitor::factory()->scheduled()->create();

    expect(app(DispatchDueMonitorChecks::class)->handle())->toBe(0);

    Queue::assertNothingPushed();
});

test('the command dispatches due monitor checks', function () {
    Queue::fake();
    Monitor::factory()->due()->create();

    $this->artisan('monitors:dispatch-due')
        ->expectsOutputToContain('Dispatched one monitor check.')
        ->assertSuccessful();

    Queue::assertPushed(CheckMonitor::class, 1);
});

test('the dispatcher command is scheduled every minute with overlap protection', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains($event->command ?? '', 'monitors:dispatch-due'));

    expect($event)
        ->not->toBeNull()
        ->expression->toBe('* * * * *')
        ->withoutOverlapping->toBeTrue()
        ->onOneServer->toBeTrue();
});
