<?php

declare(strict_types=1);

namespace App\Actions\Monitors;

use App\Enums\MonitorCheckStatus;
use App\Models\MonitorCheck;
use App\Models\MonitorCheckDailyStat;
use Carbon\CarbonInterface;

class RollUpMonitorChecks
{
    private const UPSERT_CHUNK = 500;

    /**
     * Aggregate raw checks into one row per monitor per day for the inclusive
     * range [$from, $to].
     *
     * Idempotent: rows are upserted on (monitor_id, date), so re-running for a
     * day that already has stats simply refreshes it. That matters because the
     * most recent days are re-rolled on every run to pick up checks that landed
     * after the previous run.
     *
     * @return int Number of monitor/day rows written.
     */
    public function handle(CarbonInterface $from, CarbonInterface $to): int
    {
        $rows = MonitorCheck::query()
            ->selectRaw('monitor_id')
            ->selectRaw('DATE(checked_at) AS check_date')
            ->selectRaw('COUNT(*) AS total_checks')
            ->selectRaw(
                'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS successful_checks',
                [MonitorCheckStatus::Successful->value],
            )
            ->whereBetween('checked_at', [
                $from->copy()->startOfDay(),
                $to->copy()->endOfDay(),
            ])
            ->groupBy('monitor_id')
            ->groupByRaw('DATE(checked_at)')
            ->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        $written = 0;

        foreach ($rows->chunk(self::UPSERT_CHUNK) as $chunk) {
            $payload = $chunk
                ->map(fn (MonitorCheck $row): array => [
                    'monitor_id' => (int) $row->monitor_id,
                    'date' => (string) $row->getAttribute('check_date'),
                    'total_checks' => (int) $row->getAttribute('total_checks'),
                    'successful_checks' => (int) $row->getAttribute('successful_checks'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
                ->all();

            MonitorCheckDailyStat::query()->upsert(
                $payload,
                ['monitor_id', 'date'],
                ['total_checks', 'successful_checks', 'updated_at'],
            );

            $written += count($payload);
        }

        return $written;
    }

    /**
     * Drop daily stats older than $cutoff.
     */
    public function prune(CarbonInterface $cutoff): int
    {
        return MonitorCheckDailyStat::query()
            // Plain comparison so the `date` index is usable; whereDate() would
            // wrap the column in DATE() and force a full scan.
            ->where('date', '<', $cutoff->toDateString())
            ->delete();
    }
}
