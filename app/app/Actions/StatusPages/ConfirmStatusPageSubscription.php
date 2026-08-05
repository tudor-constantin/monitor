<?php

declare(strict_types=1);

namespace App\Actions\StatusPages;

use App\Models\StatusPageSubscription;
use Illuminate\Support\Facades\DB;

class ConfirmStatusPageSubscription
{
    public function handle(StatusPageSubscription $subscription, string $token): bool
    {
        return DB::transaction(function () use ($subscription, $token): bool {
            $lockedSubscription = StatusPageSubscription::query()
                ->whereKey($subscription->getKey())
                ->lockForUpdate()
                ->sole();

            if (
                $lockedSubscription->confirmation_token_hash === null
                || $lockedSubscription->confirmation_requested_at === null
                || $lockedSubscription->confirmation_requested_at->lt(now()->subHour())
                || ! hash_equals($lockedSubscription->confirmation_token_hash, hash('sha256', $token))
            ) {
                return false;
            }

            $monitorIds = $lockedSubscription->pending_subscribed_to_all
                ? []
                : $lockedSubscription->statusPage
                    ->monitors()
                    ->whereKey($lockedSubscription->pending_monitor_ids ?? [])
                    ->pluck('monitors.id')
                    ->map(fn (mixed $monitorId): int => (int) $monitorId)
                    ->all();

            $lockedSubscription->monitors()->sync($monitorIds);
            $lockedSubscription->forceFill([
                'subscribed_to_all' => $lockedSubscription->pending_subscribed_to_all,
                'pending_monitor_ids' => null,
                'confirmation_token_hash' => null,
                'confirmation_requested_at' => null,
                'verified_at' => now(),
            ])->save();

            return true;
        });
    }
}
