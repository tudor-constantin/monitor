<?php

declare(strict_types=1);

namespace App\Concerns;

use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * Bounds the lifetime of the {@see ShouldBeUnique}
 * lock to the worst case a job can legitimately occupy it.
 *
 * Laravel defaults `uniqueFor` to 0, which makes RedisLock acquire the key with
 * a plain SETNX and therefore *no expiry*. If a worker dies between acquiring
 * and releasing (OOM kill, `docker kill`, host crash, PHP fatal error) the key
 * survives forever and every later dispatch of that job is silently discarded.
 *
 * For a monitoring application that failure is invisible and permanent: the
 * scheduler keeps advancing `next_check_at`, the dispatch keeps reporting
 * success, and the monitor is simply never checked again.
 */
trait ExpiresUniqueJobLock
{
    /**
     * Seconds the unique lock may be held before it expires on its own.
     *
     * Derived from the retry policy rather than hard-coded so the two cannot
     * drift apart: every attempt may burn the full timeout, and every backoff
     * delay is spent between attempts while the lock is still held.
     */
    public function uniqueFor(): int
    {
        $attempts = max(1, $this->tries);
        $backoffSeconds = array_sum($this->backoff);

        return ($attempts * $this->timeout) + (int) $backoffSeconds;
    }
}
