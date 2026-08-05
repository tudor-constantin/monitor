<?php

declare(strict_types=1);

namespace App\Actions\StatusPages;

use App\Models\StatusPage;
use Illuminate\Validation\ValidationException;

class SyncStatusPageMonitors
{
    /**
     * @param  list<int>  $monitorIds
     */
    public function handle(StatusPage $statusPage, array $monitorIds): void
    {
        $monitorIds = array_values(array_unique($monitorIds));
        $availableMonitorCount = $statusPage->user()
            ->firstOrFail()
            ->monitors()
            ->whereKey($monitorIds)
            ->whereDoesntHave(
                'statusPages',
                fn ($query) => $query->whereKeyNot($statusPage->getKey()),
            )
            ->count();

        if ($availableMonitorCount !== count($monitorIds)) {
            throw ValidationException::withMessages([
                'selectedMonitorIds' => __('One or more selected websites are unavailable.'),
            ]);
        }

        $pivotValues = [];

        foreach ($monitorIds as $position => $monitorId) {
            $pivotValues[$monitorId] = ['position' => $position];
        }

        $statusPage->monitors()->sync($pivotValues);
    }
}
