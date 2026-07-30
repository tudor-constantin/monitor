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
     *         segments: list<array{date: string, label: string, state: string}>
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
                $date = $startsAt->addDays($offset);
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

                $segments[] = [
                    'date' => $date->toDateString(),
                    'label' => $this->segmentLabel($date->format('M j, Y'), $state, $dailySuccessful, $dailyTotal),
                    'state' => $state,
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

    private function segmentLabel(string $date, string $state, int $successfulChecks, int $totalChecks): string
    {
        return match ($state) {
            'no-data' => "{$date}: no data",
            'operational' => "{$date}: operational ({$successfulChecks}/{$totalChecks} checks successful)",
            'outage' => "{$date}: outage (0/{$totalChecks} checks successful)",
            default => "{$date}: degraded ({$successfulChecks}/{$totalChecks} checks successful)",
        };
    }
}
