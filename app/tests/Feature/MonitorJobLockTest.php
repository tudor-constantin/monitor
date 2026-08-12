<?php

use App\Jobs\CheckMonitor;
use App\Jobs\FetchMonitorFavicon;
use App\Models\Monitor;

/**
 * The unique lock is best-effort queue hygiene, not the duplicate-check
 * guarantee -- that lives in MonitorCheckClaimTest. What is pinned here is the
 * one thing it must not do: last forever, or expire so early that the scheduler
 * stacks a copy behind every attempt.
 */
test('the unique lock expires rather than stranding a monitor forever', function () {
    $retryAfter = (int) config('queue.connections.redis.retry_after');

    $jobs = [
        new CheckMonitor(Monitor::factory()->make()),
        new FetchMonitorFavicon(Monitor::factory()->make()),
    ];

    foreach ($jobs as $job) {
        // uniqueFor 0 -- Laravel's default -- means a bare SETNX with no expiry:
        // a worker killed mid-job strands the key and the monitor is never
        // checked again. It also has to cover the retry lifecycle, where a
        // crashed attempt holds the queue reservation for a whole retry_after.
        expect($job->uniqueFor())->toBeGreaterThanOrEqual($job->tries * $retryAfter)
            ->and($job->uniqueFor())->toBeLessThan(3600);
    }
});

test('the lock covers a crashed attempt, not merely the job timeout', function () {
    config(['queue.connections.redis.retry_after' => 90]);

    // Every reachable timeout is below retry_after, so sizing the lock from the
    // timeout alone -- the original bug -- always under-counts.
    foreach ([1, 10, 30, 60] as $timeoutSeconds) {
        $job = new CheckMonitor(Monitor::factory()->make(['timeout_seconds' => $timeoutSeconds]));

        expect($job->uniqueFor())->toBeGreaterThan($job->tries * $job->timeout);
    }
});
