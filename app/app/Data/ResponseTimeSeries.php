<?php

namespace App\Data;

final readonly class ResponseTimeSeries
{
    public function __construct(
        public string $points,
        public int $sampleCount,
        public ?int $latestResponseTimeMs,
        public ?int $minimumResponseTimeMs,
        public ?int $maximumResponseTimeMs,
        public ?int $averageResponseTimeMs,
    ) {}

    public static function empty(): self
    {
        return new self('', 0, null, null, null, null);
    }
}
