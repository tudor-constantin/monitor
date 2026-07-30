<?php

namespace App\Http\Controllers;

use App\Actions\StatusPages\ConfirmStatusPageSubscription;
use App\Models\StatusPageSubscription;
use Illuminate\Http\RedirectResponse;

class ConfirmStatusPageSubscriptionController extends Controller
{
    public function __invoke(
        StatusPageSubscription $subscription,
        string $token,
        ConfirmStatusPageSubscription $confirmStatusPageSubscription,
    ): RedirectResponse {
        abort_unless(
            $confirmStatusPageSubscription->handle($subscription, $token),
            403,
            'This confirmation link is invalid or has expired.',
        );

        return redirect()
            ->route('status-pages.public', $subscription->statusPage)
            ->with('subscription_status', 'Subscription confirmed. You will now receive status updates.');
    }
}
