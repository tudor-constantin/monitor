<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\IncidentResolved;
use App\Services\StatusPages\NotifyStatusPageSubscribers;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendStatusPageRecoveredNotifications implements ShouldQueue
{
    use InteractsWithQueue;

    public string $connection = 'redis';

    public string $queue = 'notifications';

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(
        private NotifyStatusPageSubscribers $notifyStatusPageSubscribers,
    ) {}

    public function handle(IncidentResolved $event): void
    {
        $this->notifyStatusPageSubscribers->handle($event->incident, true);
    }
}
