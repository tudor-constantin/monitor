<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\FetchMonitorFavicon;
use App\Models\Monitor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('monitors:dispatch-favicon-refresh')]
#[Description('Dispatch queued favicon refreshes for all monitors')]
class DispatchMonitorFaviconRefresh extends Command
{
    public function handle(): int
    {
        $dispatched = 0;

        Monitor::query()
            ->select(['id'])
            ->orderBy('id')
            ->chunkById(200, function ($monitors) use (&$dispatched): void {
                foreach ($monitors as $monitor) {
                    FetchMonitorFavicon::dispatch($monitor);
                    $dispatched++;
                }
            });

        $this->components->info("Dispatched {$dispatched} favicon refresh jobs.");

        return self::SUCCESS;
    }
}
