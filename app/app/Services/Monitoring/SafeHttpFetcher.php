<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Contracts\DnsResolver;
use App\Exceptions\Monitoring\ResponseTooLargeException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Resolves a hostname to a public IP address and performs an outbound HTTP
 * request pinned to that address, guarding against SSRF and DNS-rebinding.
 *
 * Callers are responsible for validating the URL/scheme/port themselves
 * before calling {@see resolveAddresses()} / {@see resolveSafeAddress()} and
 * {@see send()} — this class only handles the "is this hostname safe to
 * connect to, and how do we connect to exactly the address we validated"
 * concerns shared by {@see MonitorChecker} and {@see MonitorFaviconFetcher}.
 */
class SafeHttpFetcher
{
    /** @var array<string, list<string>> */
    private array $resolvedAddressCache = [];

    public function __construct(
        private readonly DnsResolver $dnsResolver,
        private readonly IpAddressSafety $ipAddressSafety,
    ) {}

    /**
     * Resolve a hostname to its list of addresses, caching the result for
     * the lifetime of this instance so repeated lookups (e.g. across
     * redirect hops back to the same host) do not re-resolve DNS and risk
     * landing on a different address than the one that was validated.
     *
     * @return list<string>
     */
    public function resolveAddresses(string $host): array
    {
        return $this->resolvedAddressCache[$host] ??= $this->dnsResolver->resolve($host);
    }

    /**
     * Return the first address in $addresses that is not a public address,
     * or null when $addresses is empty or every address is public.
     *
     * @param  list<string>  $addresses
     */
    public function firstUnsafeAddress(array $addresses): ?string
    {
        foreach ($addresses as $address) {
            if (! $this->ipAddressSafety->isPublic($address)) {
                return $address;
            }
        }

        return null;
    }

    /**
     * Resolve a hostname and return its first address, or null when the
     * hostname cannot be resolved or any resolved address is not public.
     */
    public function resolveSafeAddress(string $host): ?string
    {
        $addresses = $this->resolveAddresses($host);

        if ($addresses === [] || $this->firstUnsafeAddress($addresses) !== null) {
            return null;
        }

        return $addresses[0];
    }

    /**
     * Clear the per-hostname resolution cache. Callers that fetch multiple,
     * unrelated origins in a single unit of work (e.g. favicon discovery
     * across redirect hops) should reset the cache before each new origin.
     */
    public function resetResolvedAddressCache(): void
    {
        $this->resolvedAddressCache = [];
    }

    /**
     * Send a request pinned to an already-validated, resolved address via
     * CURLOPT_RESOLVE, never following redirects, and aborting once the
     * response exceeds $maximumBytes.
     *
     * @throws ConnectionException
     * @throws ResponseTooLargeException when the response exceeds $maximumBytes
     */
    public function send(
        PendingRequest $request,
        string $method,
        string $url,
        string $host,
        int $port,
        string $resolvedAddress,
        int $maximumBytes,
    ): Response {
        $curlAddress = str_contains($resolvedAddress, ':') ? "[{$resolvedAddress}]" : $resolvedAddress;
        $sizeLimitExceeded = false;

        try {
            return $request
                ->withoutRedirecting()
                ->withOptions([
                    'curl' => [
                        CURLOPT_RESOLVE => ["{$host}:{$port}:{$curlAddress}"],
                    ],
                    'on_headers' => function (ResponseInterface $response) use ($maximumBytes, &$sizeLimitExceeded): void {
                        $contentLength = $response->getHeaderLine('Content-Length');

                        if ($contentLength !== '' && (int) $contentLength > $maximumBytes) {
                            $sizeLimitExceeded = true;

                            throw new ResponseTooLargeException;
                        }
                    },
                    'progress' => function (
                        float $downloadTotal,
                        float $downloadedBytes,
                        float $uploadTotal,
                        float $uploadedBytes,
                    ) use ($maximumBytes, &$sizeLimitExceeded): void {
                        if ($downloadTotal > $maximumBytes || $downloadedBytes > $maximumBytes) {
                            $sizeLimitExceeded = true;

                            throw new ResponseTooLargeException;
                        }
                    },
                ])
                ->send($method, $url);
        } catch (Throwable $exception) {
            if (! $sizeLimitExceeded || $exception instanceof ResponseTooLargeException) {
                throw $exception;
            }

            throw new ResponseTooLargeException($exception);
        }
    }
}
