<?php

declare(strict_types=1);

namespace App\Actions\Users;

use Carbon\CarbonInterface;
use Illuminate\Notifications\DatabaseNotification;

class PruneReadNotifications
{
    private const BATCH_SIZE = 1000;

    /**
     * Delete notifications the user has already read and that are older than
     * $cutoff. Unread notifications are always kept: they are the ones the
     * inbox exists to show.
     */
    public function handle(CarbonInterface $cutoff): int
    {
        $totalDeleted = 0;

        do {
            $deleted = DatabaseNotification::query()
                ->whereNotNull('read_at')
                ->where('read_at', '<', $cutoff)
                ->limit(self::BATCH_SIZE)
                ->delete();

            $totalDeleted += $deleted;

            if ($deleted === self::BATCH_SIZE) {
                // Give MySQL room to breathe between large deletes.
                usleep(50_000);
            }
        } while ($deleted === self::BATCH_SIZE);

        return $totalDeleted;
    }
}
