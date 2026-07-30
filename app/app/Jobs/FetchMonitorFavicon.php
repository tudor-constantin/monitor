<?php

namespace App\Jobs;

use App\Models\Monitor;
use App\Services\Monitoring\MonitorFaviconFetcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Log;
use Throwable;

#[DeleteWhenMissingModels]
class FetchMonitorFavicon implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public int $timeout = 15;

    public function __construct(public Monitor $monitor)
    {
        $this->onConnection('redis');
        $this->onQueue('maintenance');
    }

    public function handle(MonitorFaviconFetcher $monitorFaviconFetcher): void
    {
        $monitorFaviconFetcher->fetch($this->monitor);
    }

    public function uniqueId(): string
    {
        return (string) $this->monitor->getKey();
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Monitor favicon job failed.', [
            'monitor_id' => $this->monitor->getKey(),
            'error' => $exception?->getMessage(),
        ]);
    }
}
