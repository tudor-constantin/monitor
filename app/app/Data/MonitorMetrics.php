<?php

declare(strict_types=1);

namespace App\Data;

final readonly class MonitorMetrics
{
    public function __construct(
        public int $totalChecks,
        public int $successfulChecks,
        public int $failedChecks,
        public ?float $uptimePercentage,
        public ?int $averageResponseTimeMs,
        public ?int $minimumResponseTimeMs,
        public ?int $maximumResponseTimeMs,
        public int $incidentCount,
        public int $totalDowntimeSeconds,
    ) {}

    public function uptimeLabel(): string
    {
        if ($this->uptimePercentage === null) {
            return 'No data';
        }

        return number_format($this->uptimePercentage, 2).'%';
    }

    public function averageResponseTimeLabel(): string
    {
        return $this->responseTimeLabel($this->averageResponseTimeMs);
    }

    public function minimumResponseTimeLabel(): string
    {
        return $this->responseTimeLabel($this->minimumResponseTimeMs);
    }

    public function maximumResponseTimeLabel(): string
    {
        return $this->responseTimeLabel($this->maximumResponseTimeMs);
    }

    public function downtimeLabel(): string
    {
        $days = intdiv($this->totalDowntimeSeconds, 86400);
        $hours = intdiv($this->totalDowntimeSeconds % 86400, 3600);
        $minutes = intdiv($this->totalDowntimeSeconds % 3600, 60);
        $seconds = $this->totalDowntimeSeconds % 60;

        if ($days > 0) {
            return sprintf('%dd %dh', $days, $hours);
        }

        if ($hours > 0) {
            return sprintf('%dh %dm', $hours, $minutes);
        }

        if ($minutes > 0) {
            return sprintf('%dm %ds', $minutes, $seconds);
        }

        return sprintf('%ds', $seconds);
    }

    private function responseTimeLabel(?int $responseTimeMs): string
    {
        return $responseTimeMs === null ? 'No data' : number_format($responseTimeMs).' ms';
    }
}
