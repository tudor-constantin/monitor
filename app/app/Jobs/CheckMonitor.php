<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Monitors\PersistMonitorCheck;
use App\Concerns\ExpiresUniqueJobLock;
use App\Models\Monitor;
use App\Services\Monitoring\MonitorChecker;
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

    public function __construct(public Monitor $monitor)
    {
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

    public function handle(MonitorChecker $monitorChecker, PersistMonitorCheck $persistMonitorCheck): void
    {
        if (! $this->monitor->is_active) {
            return;
        }

        $persistMonitorCheck->handle(
            $this->monitor,
            $monitorChecker->check($this->monitor),
        );
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
