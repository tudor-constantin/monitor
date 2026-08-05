<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\StatusPages\UnsubscribeStatusPage;
use App\Models\StatusPageSubscription;
use Illuminate\Http\RedirectResponse;

class UnsubscribeStatusPageController extends Controller
{
    public function __invoke(
        StatusPageSubscription $subscription,
        UnsubscribeStatusPage $unsubscribeStatusPage,
    ): RedirectResponse {
        $statusPage = $unsubscribeStatusPage->handle($subscription);

        return redirect()
            ->route('status-pages.public', $statusPage)
            ->with('subscription_status', 'You have been unsubscribed from these status updates.');
    }
}
