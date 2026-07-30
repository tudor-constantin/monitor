<?php

namespace App\Data;

use App\Enums\MonitorCheckStatus;
use Carbon\CarbonInterface;

final readonly class MonitorCheckResult
{
    public function __construct(
        public MonitorCheckStatus $status,
        public ?int $statusCode,
        public ?int $responseTimeMs,
        public ?int $responseSizeBytes,
        public ?string $resolvedIp,
        public ?string $errorType,
        public ?string $errorMessage,
        public CarbonInterface $checkedAt,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->status === MonitorCheckStatus::Successful;
    }
}
