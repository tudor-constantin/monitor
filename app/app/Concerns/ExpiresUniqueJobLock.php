<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Actions\Monitors\ClaimMonitorCheck;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * Gives the {@see ShouldBeUnique} lock a finite lifetime.
 *
 * This keeps the scheduler from stacking copies of a job behind one that is
 * already waiting, and nothing more. It is not, and cannot be, a guarantee that
 * only one copy runs: Laravel takes the lock at dispatch, but the job does not
 * start until a worker pops it, and nothing bounds that wait -- not the first
 * one, and not the wait before a retry, which migrateExpiredJobs rpushes onto
 * the back of the queue. A deep enough backlog outlives any TTL.
 *
 * A TTL of 0 -- Laravel's default -- is worse, which is why this exists at all.
 * RedisLock would then acquire the key with a bare SETNX and no expiry, so a
 * worker killed mid-job (OOM, `docker kill`, host crash, PHP fatal) strands the
 * key forever: the scheduler keeps advancing next_check_at, every dispatch
 * keeps reporting success, and the monitor is never checked again.
 *
 * Because this is best-effort, nothing depends on it being right. Duplicate
 * checks are prevented where it is actually decidable -- at the point of doing
 * the work -- by the claim token in {@see ClaimMonitorCheck}.
 */
trait ExpiresUniqueJobLock
{
    public function uniqueFor(): int
    {
        $connection = $this->connection ?? config('queue.default');
        $retryAfter = (int) config("queue.connections.{$connection}.retry_after", 0);

        // A crash does not release the queue reservation: the job reappears
        // only after the connection's retry_after, whatever $backoff says. So
        // an attempt costs the larger of the two, plus the backoff between.
        return (max(1, $this->tries) * max($this->timeout, $retryAfter))
            + (int) array_sum($this->backoff);
    }
}
