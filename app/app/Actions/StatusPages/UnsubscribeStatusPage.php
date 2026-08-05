<?php

declare(strict_types=1);

namespace App\Actions\StatusPages;

use App\Models\StatusPage;
use App\Models\StatusPageSubscription;

class UnsubscribeStatusPage
{
    public function handle(StatusPageSubscription $subscription): StatusPage
    {
        $statusPage = $subscription->statusPage;
        $subscription->delete();

        return $statusPage;
    }
}
