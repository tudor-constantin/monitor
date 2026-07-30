<?php

namespace App\Actions\StatusPages;

use App\Models\StatusPage;
use App\Models\StatusPageSubscription;
use App\Notifications\ConfirmStatusPageSubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RequestStatusPageSubscription
{
    /**
     * @param  list<int>  $monitorIds
     */
    public function handle(
        StatusPage $statusPage,
        string $email,
        bool $subscribeToAll,
        array $monitorIds,
        string $ipAddress,
    ): StatusPageSubscription {
        abort_unless($statusPage->is_public, 404);

        $email = Str::lower(trim($email));
        $monitorIds = array_values(array_unique(array_map(intval(...), $monitorIds)));

        if (! $subscribeToAll) {
            $validMonitorIds = $statusPage->monitors()
                ->whereKey($monitorIds)
                ->pluck('monitors.id')
                ->map(fn (mixed $monitorId): int => (int) $monitorId)
                ->all();

            if ($monitorIds === [] || count($validMonitorIds) !== count($monitorIds)) {
                throw ValidationException::withMessages([
                    'selectedSubscriptionMonitorIds' => __('Select at least one valid service.'),
                ]);
            }
        }

        $rateLimitKey = sprintf(
            'status-page-subscription:%d:%s',
            $statusPage->id,
            hash('sha256', $ipAddress),
        );
        $limit = max(1, (int) config('monitoring.public_subscription_limit_per_hour', 5));
        $token = Str::random(64);

        $subscription = RateLimiter::attempt(
            $rateLimitKey,
            $limit,
            fn (): StatusPageSubscription => DB::transaction(function () use (
                $statusPage,
                $email,
                $subscribeToAll,
                $monitorIds,
                $token,
            ): StatusPageSubscription {
                $subscription = $statusPage->subscriptions()
                    ->where('email', $email)
                    ->lockForUpdate()
                    ->first();

                $subscription ??= $statusPage->subscriptions()->make([
                    'email' => $email,
                ]);

                $subscription->forceFill([
                    'pending_subscribed_to_all' => $subscribeToAll,
                    'pending_monitor_ids' => $subscribeToAll ? null : $monitorIds,
                    'confirmation_token_hash' => hash('sha256', $token),
                    'confirmation_requested_at' => now(),
                ])->save();

                return $subscription;
            }),
            3600,
        );

        if (! $subscription instanceof StatusPageSubscription) {
            throw ValidationException::withMessages([
                'email' => __('Too many subscription requests. Please try again later.'),
            ]);
        }

        Notification::route('mail', $email)->notify(
            new ConfirmStatusPageSubscription($subscription, $token),
        );

        return $subscription;
    }
}
