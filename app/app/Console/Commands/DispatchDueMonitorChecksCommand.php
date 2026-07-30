<?php

namespace App\Console\Commands;

use App\Actions\Monitors\DispatchDueMonitorChecks;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('monitors:dispatch-due')]
#[Description('Reserve due monitors and dispatch their checks')]
class DispatchDueMonitorChecksCommand extends Command
{
    public function handle(DispatchDueMonitorChecks $dispatchDueMonitorChecks): int
    {
        $dispatchedCount = $dispatchDueMonitorChecks->handle();

        $this->components->info(
            trans_choice(
                '{0} No monitor checks were dispatched.|{1} Dispatched one monitor check.|[2,*] Dispatched :count monitor checks.',
                $dispatchedCount,
                ['count' => $dispatchedCount],
            ),
        );

        return self::SUCCESS;
    }
}
