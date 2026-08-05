<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Monitors\PersistMonitorCheck;
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
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [1, 5, 10];

    public int $timeout = 60;

    public function __construct(public Monitor $monitor)
    {
        $this->onConnection('redis');
        $this->onQueue('checks');

        // The HTTP client itself is bounded by $monitor->timeout_seconds (max 60);
        // add a buffer for DNS resolution and result persistence so the job isn't
        // killed mid-check for monitors configured near that ceiling.
        $this->timeout = $monitor->timeout_seconds + 15;
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
