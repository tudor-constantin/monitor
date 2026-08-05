<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Data\MonitorMetrics;
use App\Data\ResponseTimeSeries;
use App\Enums\MonitorCheckStatus;
use App\Enums\MonitorStatus;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Number;

class MonitorMetricsService
{
    /**
     * @return Collection<int, Monitor>
     */
    public function dashboardMonitors(User $user, int $limit = 6): Collection
    {
        $checkedAfter = now()->subDay();
        $monitorTable = (new Monitor)->getTable();
        $checkTable = (new MonitorCheck)->getTable();

        return $user->monitors()
            ->select([
                "{$monitorTable}.id",
                "{$monitorTable}.user_id",
                "{$monitorTable}.name",
                "{$monitorTable}.url",
                "{$monitorTable}.favicon_path",
                "{$monitorTable}.favicon_fetched_at",
                "{$monitorTable}.status",
                "{$monitorTable}.is_active",
                "{$monitorTable}.last_checked_at",
                "{$monitorTable}.next_check_at",
            ])
            ->addSelect([
                'last_response_time_ms' => MonitorCheck::query()
                    ->select('response_time_ms')
                    ->whereColumn("{$checkTable}.monitor_id", "{$monitorTable}.id")
                    ->latest('checked_at')
                    ->limit(1),
                'uptime_24_hours' => MonitorCheck::query()
                    ->selectRaw(
                        'ROUND(AVG(CASE WHEN status = ? THEN 100 ELSE 0 END), 2)',
                        [MonitorCheckStatus::Successful->value],
                    )
                    ->whereColumn("{$checkTable}.monitor_id", "{$monitorTable}.id")
                    ->where('checked_at', '>=', $checkedAfter),
            ])
            ->withExists([
                'incidents as has_active_incident' => fn ($query) => $query->whereNull('resolved_at'),
            ])
            ->orderByRaw(
                'CASE monitors.status
                    WHEN ? THEN ?
                    WHEN ? THEN ?
                    WHEN ? THEN ?
                    WHEN ? THEN ?
                    WHEN ? THEN ?
                    ELSE ?
                END',
                [
                    MonitorStatus::Down->value, MonitorStatus::Down->sortWeight(),
                    MonitorStatus::Degraded->value, MonitorStatus::Degraded->sortWeight(),
                    MonitorStatus::Pending->value, MonitorStatus::Pending->sortWeight(),
                    MonitorStatus::Up->value, MonitorStatus::Up->sortWeight(),
                    MonitorStatus::Paused->value, MonitorStatus::Paused->sortWeight(),
                    MonitorStatus::Paused->sortWeight() + 1,
                ],
            )
            ->latest()
            ->limit($limit)
            ->get();
    }

    public function forPeriod(
        Monitor $monitor,
        CarbonInterface $startsAt,
        ?CarbonInterface $endsAt = null,
    ): MonitorMetrics {
        $endsAt ??= now();

        $checkAggregate = $monitor->checks()
            ->whereBetween('checked_at', [$startsAt, $endsAt])
            ->selectRaw(
                'COUNT(*) AS total_checks,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS successful_checks,
                AVG(response_time_ms) AS average_response_time_ms,
                MIN(response_time_ms) AS minimum_response_time_ms,
                MAX(response_time_ms) AS maximum_response_time_ms',
                [MonitorCheckStatus::Successful->value],
            )
            ->firstOrFail();

        $totalChecks = (int) $checkAggregate->getAttribute('total_checks');
        $successfulChecks = (int) $checkAggregate->getAttribute('successful_checks');
        $uptimePercentage = $totalChecks === 0
            ? null
            : round(($successfulChecks / $totalChecks) * 100, 2);

        $incidents = $this->incidentsOverlappingPeriod($monitor, $startsAt, $endsAt);

        return new MonitorMetrics(
            totalChecks: $totalChecks,
            successfulChecks: $successfulChecks,
            failedChecks: $totalChecks - $successfulChecks,
            uptimePercentage: $uptimePercentage,
            averageResponseTimeMs: $this->nullableRoundedInteger($checkAggregate->getAttribute('average_response_time_ms')),
            minimumResponseTimeMs: $this->nullableInteger($checkAggregate->getAttribute('minimum_response_time_ms')),
            maximumResponseTimeMs: $this->nullableInteger($checkAggregate->getAttribute('maximum_response_time_ms')),
            incidentCount: $incidents->count(),
            totalDowntimeSeconds: $this->totalDowntimeSeconds($incidents, $startsAt, $endsAt),
        );
    }

    public function responseTimeSeries(
        Monitor $monitor,
        CarbonInterface $startsAt,
        int $limit = 48,
    ): ResponseTimeSeries {
        $checks = $monitor->checks()
            ->select(['id', 'monitor_id', 'response_time_ms', 'checked_at'])
            ->whereNotNull('response_time_ms')
            ->where('checked_at', '>=', $startsAt)
            ->latest('checked_at')
            ->limit($limit)
            ->get()
            ->sortBy('checked_at')
            ->values();

        if ($checks->isEmpty()) {
            return ResponseTimeSeries::empty();
        }

        $responseTimes = $checks
            ->map(fn (MonitorCheck $check): int => (int) $check->response_time_ms);
        $maximum = (int) $responseTimes->max();
        $sampleDivisor = max($checks->count() - 1, 1);
        $valueDivisor = max($maximum, 1);

        $points = $responseTimes
            ->map(function (int $responseTime, int $index) use ($sampleDivisor, $valueDivisor): string {
                $x = ($index / $sampleDivisor) * 100;
                $y = 95 - (($responseTime / $valueDivisor) * 85);

                return Number::format($x, 2, locale: 'en').','.Number::format($y, 2, locale: 'en');
            })
            ->implode(' ');

        return new ResponseTimeSeries(
            points: $points,
            sampleCount: $checks->count(),
            latestResponseTimeMs: (int) $responseTimes->last(),
            minimumResponseTimeMs: (int) $responseTimes->min(),
            maximumResponseTimeMs: $maximum,
            averageResponseTimeMs: (int) round((float) $responseTimes->average()),
        );
    }

    /**
     * @return Collection<int, Incident>
     */
    private function incidentsOverlappingPeriod(
        Monitor $monitor,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
    ): Collection {
        return $monitor->incidents()
            ->select(['id', 'monitor_id', 'started_at', 'resolved_at'])
            ->where('started_at', '<=', $endsAt)
            ->where(function ($query) use ($startsAt): void {
                $query
                    ->whereNull('resolved_at')
                    ->orWhere('resolved_at', '>=', $startsAt);
            })
            ->get();
    }

    /**
     * @param  SupportCollection<int, Incident>  $incidents
     */
    private function totalDowntimeSeconds(
        SupportCollection $incidents,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
    ): int {
        return (int) $incidents->sum(function (Incident $incident) use ($startsAt, $endsAt): int {
            $incidentStart = $incident->started_at->greaterThan($startsAt)
                ? $incident->started_at
                : $startsAt;
            $incidentEnd = $incident->resolved_at?->lessThan($endsAt)
                ? $incident->resolved_at
                : $endsAt;

            return max(0, (int) $incidentStart->diffInSeconds($incidentEnd));
        });
    }

    private function nullableInteger(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function nullableRoundedInteger(mixed $value): ?int
    {
        return $value === null ? null : (int) round((float) $value);
    }
}
