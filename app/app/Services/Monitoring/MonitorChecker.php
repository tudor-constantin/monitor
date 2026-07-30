<?php

namespace App\Services\Monitoring;

use App\Contracts\DnsResolver;
use App\Data\MonitorCheckResult;
use App\Enums\MonitorCheckStatus;
use App\Models\Monitor;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class MonitorChecker
{
    private const MAX_RESPONSE_BYTES = 1048576;

    public function __construct(
        private DnsResolver $dnsResolver,
        private IpAddressSafety $ipAddressSafety,
    ) {}

    public function check(Monitor $monitor): MonitorCheckResult
    {
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
        $resolvedAddresses = $this->dnsResolver->resolve($host);

        if ($resolvedAddresses === []) {
            return $this->failure(
                MonitorCheckStatus::ConnectionError,
                'dns_resolution_failed',
                'The monitor hostname could not be resolved.',
                $checkedAt,
            );
        }

        foreach ($resolvedAddresses as $resolvedAddress) {
            if (! $this->ipAddressSafety->isPublic($resolvedAddress)) {
                return $this->failure(
                    MonitorCheckStatus::Blocked,
                    'unsafe_ip_address',
                    'The monitor hostname resolves to a non-public address.',
                    $checkedAt,
                    $resolvedAddress,
                );
            }
        }

        $resolvedAddress = $resolvedAddresses[0];
        $port = parse_url($monitor->url, PHP_URL_PORT)
            ?? (parse_url($monitor->url, PHP_URL_SCHEME) === 'https' ? 443 : 80);
        $curlAddress = str_contains($resolvedAddress, ':') ? "[{$resolvedAddress}]" : $resolvedAddress;
        $startedAt = hrtime(true);

        try {
            $response = Http::connectTimeout(min(5, $monitor->timeout_seconds))
                ->timeout($monitor->timeout_seconds)
                ->withUserAgent('Monitor/1.0')
                ->accept('*/*')
                ->withoutRedirecting()
                ->withOptions([
                    'curl' => [
                        CURLOPT_RESOLVE => ["{$host}:{$port}:{$curlAddress}"],
                    ],
                    'on_headers' => function (ResponseInterface $response): void {
                        $contentLength = $response->getHeaderLine('Content-Length');

                        if ($contentLength !== '' && (int) $contentLength > self::MAX_RESPONSE_BYTES) {
                            throw new RuntimeException('Response exceeded the maximum size.');
                        }
                    },
                    'progress' => function (
                        float $downloadTotal,
                        float $downloadedBytes,
                        float $uploadTotal,
                        float $uploadedBytes,
                    ): void {
                        if (
                            $downloadTotal > self::MAX_RESPONSE_BYTES
                            || $downloadedBytes > self::MAX_RESPONSE_BYTES
                        ) {
                            throw new RuntimeException('Response exceeded the maximum size.');
                        }
                    },
                ])
                ->send($monitor->method, $monitor->url);
        } catch (RuntimeException) {
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
