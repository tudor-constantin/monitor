<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Users\PruneReadNotifications;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('notifications:prune {--days= : Override the configured retention period}')]
#[Description('Delete read in-app notifications older than the retention period')]
class PruneNotifications extends Command
{
    public function handle(PruneReadNotifications $pruneReadNotifications): int
    {
        $daysOption = $this->option('days');
        $days = (int) ($daysOption === null || $daysOption === ''
            ? config('monitoring.notification_retention_days', 30)
            : $daysOption);

        if ($days < 1) {
            $this->components->error('The retention period must be at least one day.');

            return self::FAILURE;
        }

        $deleted = $pruneReadNotifications->handle(now()->subDays($days));

        $this->components->info("Deleted {$deleted} read notifications.");

        return self::SUCCESS;
    }
}
