<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Monitors\ClaimMonitorCheck;
use App\Actions\Monitors\PersistMonitorCheck;
use App\Concerns\ExpiresUniqueJobLock;
use App\Models\Monitor;
use App\Services\Monitoring\MonitorChecker;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Log;
use Throwable;

#[DeleteWhenMissingModels]
class CheckMonitor implements ShouldBeUnique, ShouldQueue
{
    use ExpiresUniqueJobLock, Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [1, 5, 10];

    public int $timeout = 60;

    /**
     * Seconds added on top of the HTTP budget for DNS resolution and for
     * persisting the result, so a monitor configured at the ceiling is not
     * killed mid-check.
     */
    private const OVERHEAD_SECONDS = 15;

    /**
     * The scheduling slot this dispatch is the check for.
     *
     * Fixed here and serialised with the job, so a retry -- or a second copy
     * the queue's best-effort unique lock let through -- carries the same slot
     * as the original and cannot record a second result for it.
     */
    public CarbonInterface $checkEpoch;

    public function __construct(public Monitor $monitor, ?CarbonInterface $checkEpoch = null)
    {
        // An ad-hoc dispatch is its own slot; only the scheduler has a
        // meaningful one to hand over.
        // Truncated because the column that records a spent slot holds whole
        // seconds: comparing a sub-second value against the value read back
        // would make every copy look newer than the one before it.
        $this->checkEpoch = ($checkEpoch ?? CarbonImmutable::now())->toImmutable()->startOfSecond();

        $this->onConnection('redis');
        $this->onQueue('checks');

        $this->timeout = $this->httpBudgetSeconds($monitor) + self::OVERHEAD_SECONDS;
    }

    /**
     * The HTTP budget handle() will actually give this check.
     *
     * The queue timeout is a scalar captured at dispatch time, while handle()
     * runs against a fully rehydrated model. If the model we were handed does
     * not carry timeout_seconds — the scheduler loads due monitors with a
     * partial column list — reading it would yield null, null + overhead would
     * silently produce a 15 second timeout, and the worker would kill checks
     * mid-flight without ever persisting a result. Assume the configured
     * ceiling instead: an over-long queue timeout costs a worker slot, an
     * under-long one loses monitoring data without a trace.
     */
    private function httpBudgetSeconds(Monitor $monitor): int
    {
        if (! array_key_exists('timeout_seconds', $monitor->getAttributes())) {
            return (int) config('monitoring.max_timeout_seconds', 60);
        }

        return (int) $monitor->timeout_seconds;
    }

    /**
     * Seconds a run may own its monitor before another run may take over.
     *
     * Deliberately not load-bearing. Too short and a straggler simply discards
     * its own result; too long and a crashed run makes the monitor wait. Either
     * way the claim token, not this number, is what stops a duplicate check
     * being recorded.
     */
    private const CLAIM_LEASE_SECONDS = 300;

    public function handle(
        MonitorChecker $monitorChecker,
        PersistMonitorCheck $persistMonitorCheck,
        ClaimMonitorCheck $claimMonitorCheck,
    ): void {
        if (! $this->monitor->is_active) {
            return;
        }

        $token = $claimMonitorCheck->acquire($this->monitor, self::CLAIM_LEASE_SECONDS);

        if ($token === null) {
            // Another run owns this monitor and is still inside its lease.
            return;
        }

        try {
            // Checked again here because acquiring the claim proves nothing
            // about the outbound request that follows: this run may have sat in
            // the queue, and the work below is the expensive, externally
            // visible part worth not duplicating.
            if (! $claimMonitorCheck->isStillHeldBy($this->monitor, $token)) {
                return;
            }

            // Checked before the request, not just before the write: a copy of
            // a slot that already has its result would otherwise still hit the
            // monitored site, which is the duplicate outbound check this is
            // meant to prevent.
            if ($persistMonitorCheck->alreadyRecorded($this->monitor, $this->checkEpoch)) {
                return;
            }

            $persistMonitorCheck->handle(
                $this->monitor,
                $monitorChecker->check($this->monitor),
                $token,
                $this->checkEpoch,
            );
        } finally {
            $claimMonitorCheck->release($this->monitor, $token);
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->monitor->getKey();
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Monitor check job failed.', [
            'monitor_id' => $this->monitor->getKey(),
            'error' => $exception?->getMessage(),
        ]);
    }
}
