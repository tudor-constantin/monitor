<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Monitors\FindStaleMonitors;
use App\Models\Monitor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('monitors:report-stale {--sample=25 : How many stale monitors to name}')]
#[Description('Report active monitors that stopped producing checks')]
class ReportStaleMonitors extends Command
{
    public function handle(FindStaleMonitors $findStaleMonitors): int
    {
        $total = $findStaleMonitors->count();

        if ($total === 0) {
            $this->components->info('No stale monitors.');

            return self::SUCCESS;
        }

        $sampleSize = max(1, (int) $this->option('sample'));
        $sample = $findStaleMonitors->handle($sampleSize);

        // Deliberately a bounded sample, not the full set. The failures this
        // detects are correlated — Redis being unavailable makes every monitor
        // stale at once — so logging them all would emit a log line with one
        // entry per monitor, every hour, exactly when the system is degraded.
        Log::warning('Active monitors have stopped producing checks.', [
            'stale_count' => $total,
            'sample' => $sample
                ->map(fn (Monitor $monitor): array => [
                    'monitor_id' => $monitor->getKey(),
                    'name' => $monitor->name,
                    'interval_seconds' => $monitor->interval_seconds,
                    'last_checked_at' => $monitor->last_checked_at?->toIso8601String(),
                ])
                ->all(),
        ]);

        $this->components->warn(
            "{$total} active monitor(s) have not been checked recently.",
        );

        $this->table(
            ['ID', 'Name', 'Interval (s)', 'Last checked'],
            $sample->map(fn (Monitor $monitor): array => [
                $monitor->getKey(),
                $monitor->name,
                $monitor->interval_seconds,
                $monitor->last_checked_at?->diffForHumans() ?? 'never',
            ])->all(),
        );

        if ($total > $sample->count()) {
            $this->components->info(
                'Showing '.$sample->count()." of {$total}; pass --sample to widen.",
            );
        }

        return self::SUCCESS;
    }
}
