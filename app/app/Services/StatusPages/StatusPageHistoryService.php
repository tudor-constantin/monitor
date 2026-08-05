<?php

declare(strict_types=1);

namespace App\Services\StatusPages;

use App\Enums\MonitorCheckStatus;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class StatusPageHistoryService
{
    /**
     * Build the daily uptime history, reusing a recent result when possible.
     *
     * The underlying aggregate groups every check in the window by day: with 25
     * services over 90 days at a one-minute interval that is millions of rows,
     * and the public status page both renders it on load and re-renders it on a
     * 60-second poll for every anonymous visitor. Daily buckets barely move
     * between polls, so a short cache removes essentially all of that cost.
     *
     * @param  Collection<int, Monitor>  $monitors
     * @return array{
     *     starts_at: string,
     *     ends_at: string,
     *     monitors: array<int, array{
     *         uptime_percentage: float|null,
     *         total_checks: int,
     *         segments: list<array{
     *             date: string,
     *             date_label: string,
     *             label: string,
     *             state: string,
     *             state_label: string,
     *             successful_checks: int,
     *             total_checks: int,
     *             uptime_percentage: float|null
     *         }>
     *     }>
     * }
     */
    public function forMonitors(Collection $monitors, int $days): array
    {
        $days = in_array($days, [30, 90], true) ? $days : 30;
        $cacheSeconds = (int) config('monitoring.status_page_history_cache_seconds', 300);

        if ($cacheSeconds < 1) {
            return $this->build($monitors, $days);
        }

        return Cache::remember(
            $this->cacheKey($monitors, $days),
            $cacheSeconds,
            fn (): array => $this->build($monitors, $days),
        );
    }

    /**
     * @param  Collection<int, Monitor>  $monitors
     */
    private function cacheKey(Collection $monitors, int $days): string
    {
        $monitorIds = $monitors->modelKeys();
        sort($monitorIds);

        return sprintf(
            'status-page-history:%d:%s:%s',
            $days,
            // The window is anchored to today, so the key must roll over at
            // midnight even when an entry has not expired yet.
            now()->toDateString(),
            hash('xxh128', implode(',', array_map(strval(...), $monitorIds))),
        );
    }

    /**
     * @param  Collection<int, Monitor>  $monitors
     * @return array{
     *     starts_at: string,
     *     ends_at: string,
     *     monitors: array<int, array{
     *         uptime_percentage: float|null,
     *         total_checks: int,
     *         segments: list<array{
     *             date: string,
     *             date_label: string,
     *             label: string,
     *             state: string,
     *             state_label: string,
     *             successful_checks: int,
     *             total_checks: int,
     *             uptime_percentage: float|null
     *         }>
     *     }>
     * }
     */
    private function build(Collection $monitors, int $days): array
    {
        $startsAt = now()->startOfDay()->subDays($days - 1);
        $endsAt = now()->endOfDay();
        $monitorIds = array_values(array_map(intval(...), $monitors->modelKeys()));
        $dailyCounts = $this->dailyCounts($monitorIds, $startsAt, $endsAt);

        $history = [];

        foreach ($monitors as $monitor) {
            $segments = [];
            $totalChecks = 0;
            $successfulChecks = 0;

            for ($offset = 0; $offset < $days; $offset++) {
                $date = $startsAt->copy()->addDays($offset);
                $daily = $dailyCounts[$monitor->id.':'.$date->toDateString()] ?? null;
                $dailyTotal = (int) ($daily['total_checks'] ?? 0);
                $dailySuccessful = (int) ($daily['successful_checks'] ?? 0);
                $totalChecks += $dailyTotal;
                $successfulChecks += $dailySuccessful;

                $state = match (true) {
                    $dailyTotal === 0 => 'no-data',
                    $dailySuccessful === $dailyTotal => 'operational',
                    $dailySuccessful === 0 => 'outage',
                    default => 'degraded',
                };
                $stateLabel = $this->stateLabel($state);
                $dailyUptimePercentage = $dailyTotal === 0
                    ? null
                    : round(($dailySuccessful / $dailyTotal) * 100, 2);

                $segments[] = [
                    'date' => $date->toDateString(),
                    'date_label' => $date->format('D, M j, Y'),
                    'label' => $this->segmentLabel(
                        $date->format('D, M j, Y'),
                        $stateLabel,
                        $dailySuccessful,
                        $dailyTotal,
                        $dailyUptimePercentage,
                    ),
                    'state' => $state,
                    'state_label' => $stateLabel,
                    'successful_checks' => $dailySuccessful,
                    'total_checks' => $dailyTotal,
                    'uptime_percentage' => $dailyUptimePercentage,
                ];
            }

            $history[$monitor->id] = [
                'uptime_percentage' => $totalChecks === 0
                    ? null
                    : round(($successfulChecks / $totalChecks) * 100, 2),
                'total_checks' => $totalChecks,
                'segments' => $segments,
            ];
        }

        return [
            'starts_at' => $startsAt->format('M j, Y'),
            'ends_at' => $endsAt->format('M j, Y'),
            'monitors' => $history,
        ];
    }

    /**
     * Per-monitor, per-day check counts keyed by "monitorId:Y-m-d".
     *
     * Grouped in one query per render rather than per monitor, then indexed by
     * "monitorId:Y-m-d" so building the segments is a lookup rather than a scan.
     *
     * @param  list<int>  $monitorIds
     * @return array<string, array{total_checks: int, successful_checks: int}>
     */
    private function dailyCounts(array $monitorIds, CarbonInterface $startsAt, CarbonInterface $endsAt): array
    {
        if ($monitorIds === []) {
            return [];
        }

        $counts = [];

        $liveChecks = MonitorCheck::query()
            ->select('monitor_id')
            ->selectRaw('DATE(checked_at) AS check_date')
            ->selectRaw('COUNT(*) AS total_checks')
            ->selectRaw(
                'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS successful_checks',
                [MonitorCheckStatus::Successful->value],
            )
            ->whereIn('monitor_id', $monitorIds)
            ->whereBetween('checked_at', [$startsAt, $endsAt])
            ->groupBy('monitor_id')
            ->groupByRaw('DATE(checked_at)')
            ->get();

        foreach ($liveChecks as $check) {
            $counts[$check->monitor_id.':'.$check->getAttribute('check_date')] = [
                'total_checks' => (int) $check->getAttribute('total_checks'),
                'successful_checks' => (int) $check->getAttribute('successful_checks'),
            ];
        }

        return $counts;
    }

    private function stateLabel(string $state): string
    {
        return match ($state) {
            'operational' => 'No incidents',
            'degraded' => 'Degraded',
            'outage' => 'Outage',
            default => 'No data',
        };
    }

    private function segmentLabel(
        string $date,
        string $state,
        int $successfulChecks,
        int $totalChecks,
        ?float $uptimePercentage,
    ): string {
        $uptime = $uptimePercentage === null
            ? 'daily uptime unavailable'
            : number_format($uptimePercentage, 2).'% daily uptime';

        return "{$date}: {$state}; {$successfulChecks} of {$totalChecks} checks successful; {$uptime}";
    }
}
