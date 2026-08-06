<?php

use App\Jobs\CheckMonitor;
use App\Jobs\FetchMonitorFavicon;
use App\Models\Monitor;
use Illuminate\Support\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

test('the unique lock on a monitor check expires instead of being held forever', function () {
    $monitor = Monitor::factory()->create(['timeout_seconds' => 10]);
    $job = new CheckMonitor($monitor);

    // Laravel defaults uniqueFor to 0, which acquires the Redis key with a bare
    // SETNX and no TTL: a worker killed mid-job would strand it and the monitor
    // would never be checked again.
    expect($job->uniqueFor())->toBeGreaterThan(0)
        // Must outlast the worst case the retry policy allows, or a second check
        // could start while the first is still retrying.
        ->and($job->uniqueFor())->toBeGreaterThanOrEqual($job->tries * $job->timeout);
});

test('the unique lock on a favicon fetch also expires', function () {
    $job = new FetchMonitorFavicon(Monitor::factory()->create());

    expect($job->uniqueFor())->toBeGreaterThanOrEqual($job->tries * $job->timeout);
});

test('the unique lock outlives a worker crash and the queue visibility timeout', function () {
    // A worker killed mid-job does not release the Redis queue reservation:
    // the job only becomes visible again after the connection's retry_after.
    // If the unique lock expires before that, the scheduler can dispatch a
    // duplicate check for the same monitor while the original is still
    // reserved for retry.
    config(['queue.connections.redis.retry_after' => 90]);

    $monitor = Monitor::factory()->create(['timeout_seconds' => 10]);
    $job = new CheckMonitor($monitor);

    $retryAfter = (int) config('queue.connections.redis.retry_after');
    $worstCaseHoldTime = $job->tries * ($job->timeout + $retryAfter);

    expect($job->uniqueFor())->toBeGreaterThanOrEqual($worstCaseHoldTime);
});
