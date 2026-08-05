<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Data\MonitorCheckResult;
use App\Enums\MonitorCheckStatus;
use App\Exceptions\Monitoring\ResponseTooLargeException;
use App\Exceptions\Monitoring\TooManyRedirectsException;
use App\Exceptions\Monitoring\UnsafeRequestException;
use App\Models\Monitor;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MonitorChecker
{
    private const MAX_RESPONSE_BYTES = 1048576;

    /**
     * Redirects are followed because refusing them reports every site that
     * moves apex to www, or HTTP to HTTPS, as permanently down. Every hop is
     * re-validated by SafeHttpFetcher, so following them is not an SSRF hole.
     */
    private const MAX_REDIRECTS = 5;

    private const CONNECT_TIMEOUT_SECONDS = 5;

    public function __construct(
        private readonly SafeHttpFetcher $safeHttpFetcher,
    ) {}

    public function check(Monitor $monitor): MonitorCheckResult
    {
        $this->safeHttpFetcher->resetResolvedAddressCache();
        $checkedAt = now();

        try {
            // Pre-flight so an unusable URL, an unresolvable host, or a private
            // address is reported precisely instead of as a generic failure.
            // sendFollowingRedirects() re-resolves from the same per-check DNS
            // cache, so this costs no extra lookup and cannot disagree.
            [, , $resolvedAddresses] = $this->safeHttpFetcher->resolveRequestTarget($monitor->url);
        } catch (UnsafeRequestException $exception) {
            return $this->unsafeRequestFailure($exception, $checkedAt);
        }

        $resolvedAddress = $resolvedAddresses[0];

        // The whole redirect chain shares the monitor's timeout budget, so a
        // check can never outlive the job timeout no matter how many hops it
        // takes. hrtime() measures elapsed time, microtime() bounds it.
        $startedAt = hrtime(true);
        $deadline = microtime(true) + $this->budgetSeconds($monitor);

        try {
            $result = $this->safeHttpFetcher->sendFollowingRedirects(
                fn (int $timeoutSeconds) => Http::connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $timeoutSeconds))
                    ->timeout($timeoutSeconds)
                    ->withUserAgent('Monitor/1.0')
                    ->accept('*/*'),
                $monitor->method,
                $monitor->url,
                self::MAX_RESPONSE_BYTES,
                self::MAX_REDIRECTS,
                $deadline,
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
        } catch (TooManyRedirectsException $exception) {
            return $this->failure(
                MonitorCheckStatus::InvalidResponse,
                'too_many_redirects',
                "The request was redirected more than {$exception->maximumRedirects} times.",
                $checkedAt,
                $resolvedAddress,
                $this->elapsedMilliseconds($startedAt),
            );
        } catch (UnsafeRequestException $exception) {
            return $this->failure(
                MonitorCheckStatus::Blocked,
                'unsafe_redirect',
                'The website redirected somewhere that is not safe to check: '
                    .Str::limit($exception->getMessage(), 500),
                $checkedAt,
                $exception->address() ?? $resolvedAddress,
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

        $response = $result->response;
        $responseSizeBytes = strlen($response->body());

        if ($responseSizeBytes > self::MAX_RESPONSE_BYTES) {
            return $this->failure(
                MonitorCheckStatus::InvalidResponse,
                'response_too_large',
                'The response exceeded the maximum allowed size.',
                $checkedAt,
                $result->resolvedAddress,
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
            responseSizeBytes: $responseSizeBytes,
            resolvedIp: $result->resolvedAddress,
            errorType: $status === MonitorCheckStatus::Failed ? 'unexpected_status_code' : null,
            errorMessage: $status === MonitorCheckStatus::Failed
                ? $this->unexpectedStatusMessage($monitor, $response->status(), $result->effectiveUrl)
                : null,
            checkedAt: $checkedAt,
        );
    }

    /**
     * The HTTP budget for this check, never above the configured ceiling.
     *
     * CheckMonitor sizes the queue timeout from that same ceiling whenever it
     * cannot read the monitor's own value. Clamping here makes "the queue
     * timeout always outlasts the request it supervises" hold structurally,
     * including for monitors stored before the ceiling was lowered, rather
     * than depending on the two numbers happening to agree.
     */
    private function budgetSeconds(Monitor $monitor): int
    {
        $ceiling = max(1, (int) config('monitoring.max_timeout_seconds', 60));

        return max(1, min((int) $monitor->timeout_seconds, $ceiling));
    }

    private function unexpectedStatusMessage(
        Monitor $monitor,
        int $statusCode,
        string $effectiveUrl,
    ): string {
        $message = "Expected HTTP {$monitor->expected_status_code}, received {$statusCode}.";

        return $effectiveUrl === $monitor->url
            ? $message
            : "{$message} Followed redirects to {$effectiveUrl}.";
    }

    private function unsafeRequestFailure(
        UnsafeRequestException $exception,
        CarbonInterface $checkedAt,
    ): MonitorCheckResult {
        [$status, $message] = match ($exception->errorType()) {
            'dns_resolution_failed' => [
                MonitorCheckStatus::ConnectionError,
                'The monitor hostname could not be resolved.',
            ],
            'unsafe_ip_address' => [
                MonitorCheckStatus::Blocked,
                'The monitor hostname resolves to a non-public address.',
            ],
            default => [
                MonitorCheckStatus::Blocked,
                'The monitor URL must use HTTP or HTTPS on port 80 or 443 without credentials.',
            ],
        };

        return $this->failure(
            $status,
            $exception->errorType(),
            $message,
            $checkedAt,
            $exception->address(),
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
