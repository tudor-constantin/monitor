<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Data\MonitorCheckResult;
use App\Enums\MonitorCheckStatus;
use App\Exceptions\Monitoring\ResponseTooLargeException;
use App\Models\Monitor;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MonitorChecker
{
    private const MAX_RESPONSE_BYTES = 1048576;

    public function __construct(
        private readonly SafeHttpFetcher $safeHttpFetcher,
    ) {}

    public function check(Monitor $monitor): MonitorCheckResult
    {
        $this->safeHttpFetcher->resetResolvedAddressCache();
        $checkedAt = now();
        $host = parse_url($monitor->url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return $this->failure(
                MonitorCheckStatus::Blocked,
                'invalid_host',
                'The monitor URL does not contain a valid host.',
                $checkedAt,
            );
        }

        $host = Str::of($host)->trim('[]')->toString();
        $resolvedAddresses = $this->safeHttpFetcher->resolveAddresses($host);

        if ($resolvedAddresses === []) {
            return $this->failure(
                MonitorCheckStatus::ConnectionError,
                'dns_resolution_failed',
                'The monitor hostname could not be resolved.',
                $checkedAt,
            );
        }

        $unsafeAddress = $this->safeHttpFetcher->firstUnsafeAddress($resolvedAddresses);

        if ($unsafeAddress !== null) {
            return $this->failure(
                MonitorCheckStatus::Blocked,
                'unsafe_ip_address',
                'The monitor hostname resolves to a non-public address.',
                $checkedAt,
                $unsafeAddress,
            );
        }

        $resolvedAddress = $resolvedAddresses[0];
        $parsedPort = parse_url($monitor->url, PHP_URL_PORT);
        $port = is_int($parsedPort)
            ? $parsedPort
            : (parse_url($monitor->url, PHP_URL_SCHEME) === 'https' ? 443 : 80);
        $startedAt = hrtime(true);

        try {
            $response = $this->safeHttpFetcher->send(
                Http::connectTimeout(min(5, $monitor->timeout_seconds))
                    ->timeout($monitor->timeout_seconds)
                    ->withUserAgent('Monitor/1.0')
                    ->accept('*/*'),
                $monitor->method,
                $monitor->url,
                $host,
                $port,
                $resolvedAddress,
                self::MAX_RESPONSE_BYTES,
            );
        } catch (ResponseTooLargeException) {
            return $this->failure(
                MonitorCheckStatus::InvalidResponse,
                'response_too_large',
                'The response exceeded the maximum allowed size.',
                $checkedAt,
                $resolvedAddress,
                $this->elapsedMilliseconds($startedAt),
            );
        } catch (ConnectionException $exception) {
            $status = Str::contains(Str::lower($exception->getMessage()), 'timed out')
                ? MonitorCheckStatus::Timeout
                : MonitorCheckStatus::ConnectionError;

            return $this->failure(
                $status,
                $status->value,
                Str::limit($exception->getMessage(), 1000),
                $checkedAt,
                $resolvedAddress,
                $this->elapsedMilliseconds($startedAt),
            );
        }

        if (strlen($response->body()) > self::MAX_RESPONSE_BYTES) {
            return $this->failure(
                MonitorCheckStatus::InvalidResponse,
                'response_too_large',
                'The response exceeded the maximum allowed size.',
                $checkedAt,
                $resolvedAddress,
                $this->elapsedMilliseconds($startedAt),
            );
        }

        $status = $response->status() === $monitor->expected_status_code
            ? MonitorCheckStatus::Successful
            : MonitorCheckStatus::Failed;

        return new MonitorCheckResult(
            status: $status,
            statusCode: $response->status(),
            responseTimeMs: $this->elapsedMilliseconds($startedAt),
            responseSizeBytes: strlen($response->body()),
            resolvedIp: $resolvedAddress,
            errorType: $status === MonitorCheckStatus::Failed ? 'unexpected_status_code' : null,
            errorMessage: $status === MonitorCheckStatus::Failed
                ? "Expected HTTP {$monitor->expected_status_code}, received {$response->status()}."
                : null,
            checkedAt: $checkedAt,
        );
    }

    private function failure(
        MonitorCheckStatus $status,
        string $errorType,
        string $errorMessage,
        CarbonInterface $checkedAt,
        ?string $resolvedIp = null,
        ?int $responseTimeMs = null,
    ): MonitorCheckResult {
        return new MonitorCheckResult(
            status: $status,
            statusCode: null,
            responseTimeMs: $responseTimeMs,
            responseSizeBytes: null,
            resolvedIp: $resolvedIp,
            errorType: $errorType,
            errorMessage: $errorMessage,
            checkedAt: $checkedAt,
        );
    }

    private function elapsedMilliseconds(int $startedAt): int
    {
        return max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }
}
