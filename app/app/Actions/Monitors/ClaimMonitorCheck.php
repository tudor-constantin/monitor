<?php

declare(strict_types=1);

namespace App\Actions\Monitors;

use App\Models\Monitor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Hands a single run of a monitor check exclusive ownership of that monitor.
 *
 * Every lock in this path used to be a wall clock TTL, and every one of them
 * failed the same way: the holder cannot refresh a key while it is blocked
 * inside a C call, and PHP has plenty of those with no bound at all --
 * dns_get_record takes no timeout, and a worker's own $timeout is a userland
 * SIGALRM handler that cannot preempt curl_exec or a blocking PDO read. A
 * lapsed key therefore never meant "the holder is dead", only "the holder has
 * been slow", and treating the two as the same is what let a second check run
 * beside a live one.
 *
 * The claim below is not a lock, so it does not have to answer that question.
 * It is a token: {@see self::acquire()} mints one, and every later step
 * re-checks that the token in the row is still its own. A run that has been
 * superseded finds out and abandons its result instead of writing it. The lease
 * only decides how long a crashed run makes a monitor wait -- get it wrong and
 * checks are late, never duplicated.
 */
class ClaimMonitorCheck
{
    /**
     * Claim the monitor for this run, unless a live claim already holds it.
     *
     * The read and the write are one statement, so two workers racing here
     * cannot both come away believing they own the monitor.
     */
    public function acquire(Monitor $monitor, int $leaseSeconds, ?CarbonImmutable $now = null): ?string
    {
        $now = $now ?? CarbonImmutable::now();
        $token = (string) Str::uuid();

        $claimed = Monitor::query()
            ->whereKey($monitor->getKey())
            ->where(function ($query) use ($now): void {
                $query->whereNull('check_claim_token')
                    ->orWhere('check_claim_expires_at', '<=', $now);
            })
            ->update([
                'check_claim_token' => $token,
                'check_claim_expires_at' => $now->addSeconds($leaseSeconds),
            ]);

        return $claimed === 1 ? $token : null;
    }

    /**
     * Whether this run still owns the monitor.
     *
     * Read outside any transaction the caller may hold, so it sees claims
     * committed by other workers rather than a snapshot from before they ran.
     */
    public function isStillHeldBy(Monitor $monitor, string $token): bool
    {
        return DB::table('monitors')
            ->where('id', $monitor->getKey())
            ->where('check_claim_token', $token)
            ->exists();
    }

    /**
     * Give the monitor back, but only if this run still owns it.
     */
    public function release(Monitor $monitor, string $token): void
    {
        Monitor::query()
            ->whereKey($monitor->getKey())
            ->where('check_claim_token', $token)
            ->update([
                'check_claim_token' => null,
                'check_claim_expires_at' => null,
            ]);
    }
}
