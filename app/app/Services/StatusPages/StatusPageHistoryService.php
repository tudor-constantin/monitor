<?php

namespace App\Services\StatusPages;

use App\Enums\MonitorCheckStatus;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use Illuminate\Database\Eloquent\Collection;

class StatusPageHistoryService
{
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
    public function forMonitors(Collection $monitors, int $days): array
    {
        $days = in_array($days, [30, 90], true) ? $days : 30;
        $startsAt = now()->startOfDay()->subDays($days - 1);
        $endsAt = now()->endOfDay();
        $monitorIds = $monitors->modelKeys();

        $dailyChecks = $monitorIds === []
            ? collect()
            : MonitorCheck::query()
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
                ->get()
                ->keyBy(fn (MonitorCheck $check): string => $check->monitor_id.':'.$check->getAttribute('check_date'));

        $history = [];

        foreach ($monitors as $monitor) {
            $segments = [];
            $totalChecks = 0;
            $successfulChecks = 0;

            for ($offset = 0; $offset < $days; $offset++) {
                $date = $startsAt->copy()->addDays($offset);
                $dailyCheck = $dailyChecks->get($monitor->id.':'.$date->toDateString());
                $dailyTotal = (int) optional($dailyCheck)->getAttribute('total_checks');
                $dailySuccessful = (int) optional($dailyCheck)->getAttribute('successful_checks');
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
