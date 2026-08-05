<?php

declare(strict_types=1);

namespace App\Services\StatusPages;

use App\Models\Incident;
use App\Models\StatusPageSubscription;
use App\Notifications\StatusPageIncidentNotification;
use Illuminate\Support\Facades\Notification;

class NotifyStatusPageSubscribers
{
    public function handle(Incident $incident, bool $recovered): void
    {
        $monitorId = $incident->monitor_id;

        StatusPageSubscription::query()
            ->select([
                'id',
                'status_page_id',
                'email',
                'subscribed_to_all',
                'verified_at',
            ])
            ->whereNotNull('verified_at')
            ->whereHas('statusPage', function ($query) use ($monitorId): void {
                $query
                    ->where('is_public', true)
                    ->whereHas('monitors', fn ($query) => $query->whereKey($monitorId));
            })
            ->where(function ($query) use ($monitorId): void {
                $query
                    ->where('subscribed_to_all', true)
                    ->orWhereHas('monitors', fn ($query) => $query->whereKey($monitorId));
            })
            ->with('statusPage:id,name,slug,is_public')
            ->orderBy('id')
            ->chunkById(200, function ($subscriptions) use ($incident, $recovered): void {
                foreach ($subscriptions as $subscription) {
                    Notification::route('mail', $subscription->email)->notify(
                        new StatusPageIncidentNotification($incident, $subscription, $recovered),
                    );
                }
            });
    }
}
