<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Contracts\DnsResolver;
use App\Data\SafeHttpResult;
use App\Exceptions\Monitoring\ResponseTooLargeException;
use App\Exceptions\Monitoring\TooManyRedirectsException;
use App\Exceptions\Monitoring\UnsafeRequestException;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Resolves a hostname to a public IP address and performs an outbound HTTP
 * request pinned to that address, guarding against SSRF and DNS-rebinding.
 *
 * {@see send()} is the single-hop primitive: it assumes the caller has already
 * validated the URL and resolved a safe address. {@see sendFollowingRedirects()}
 * builds on it and re-runs the *full* safety check — scheme, port, credentials,
 * hostname resolution and address reachability — against every redirect target,
 * so a public URL cannot bounce a request into a private network.
 */
class SafeHttpFetcher
{
    /**
     * Ports an outbound request may target. Deliberately the same set the
     * SafePublicUrl validation rule accepts when a monitor is created, so a
     * redirect cannot reach a port the user was never allowed to enter.
     */
    private const ALLOWED_PORTS = [80, 443];

    /**
     * How many of a hostname's addresses a single hop may try before giving up.
     * Multi-homed hosts (CDNs, load balancers) routinely publish several
     * records and reporting a site down because the *first* one is unreachable
     * is a false alarm; the cap keeps a pathological record set bounded.
     */
    private const MAX_ADDRESS_ATTEMPTS = 3;

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
     * Clear the per-hostname resolution cache.
     *
     * Call this once at the start of a unit of work, never between hops of one:
     * the cache is what guarantees the address a hop was validated against is
     * the address it connects to, so clearing it mid-chain would reopen the
     * DNS-rebinding window it exists to close.
     */
    public function resetResolvedAddressCache(): void
    {
        $this->resolvedAddressCache = [];
    }

    /**
     * Send a request, following up to $maximumRedirects redirects and applying
     * the full safety check to every hop.
     *
     * $requestBuilder receives the seconds remaining before $deadline and must
     * return the PendingRequest for that hop, so a redirect chain can never
     * outlive the caller's overall time budget.
     *
     * @param  Closure(int): PendingRequest  $requestBuilder
     * @param  float  $deadline  A microtime(true) instant the chain must finish by.
     *
     * @throws ConnectionException when a hop fails to connect or the deadline passes
     * @throws ResponseTooLargeException when a hop exceeds $maximumBytes
     * @throws TooManyRedirectsException when the chain exceeds $maximumRedirects
     * @throws UnsafeRequestException when a hop is not safe to connect to
     */
    public function sendFollowingRedirects(
        Closure $requestBuilder,
        string $method,
        string $url,
        int $maximumBytes,
        int $maximumRedirects,
        float $deadline,
    ): SafeHttpResult {
        for ($redirectCount = 0; $redirectCount <= $maximumRedirects; $redirectCount++) {
            if ($deadline - microtime(true) <= 0) {
                // Checked before resolving, not just before sending: a hop to a
                // new host costs a DNS lookup, and dns_get_record cannot be
                // given a timeout, so starting past the deadline is how a
                // check overruns the job timeout that is supposed to contain it.
                // Only the deadline actually having passed is rejected here:
                // the minimum allowed monitor timeout is 1 second, and
                // requiring a full second of headroom on top of that would
                // reject every one-second check before it could be sent.
                throw new ConnectionException(
                    "The request to [{$url}] timed out before it could be sent.",
                );
            }

            [$host, $port, $addresses] = $this->resolveRequestTarget($url);

            [$response, $resolvedAddress] = $this->sendToFirstReachableAddress(
                $requestBuilder,
                $method,
                $url,
                $host,
                $port,
                $addresses,
                $maximumBytes,
                $deadline,
            );

            $location = $response->redirect()
                ? $this->resolveLocation($url, (string) $response->header('Location'))
                : null;

            if ($location === null) {
                // Either not a redirect, or a 3xx we cannot follow: a 304, a 300
                // with no choice made, or a Location header that is missing or
                // unusable. None of those are a safety problem, so hand the
                // response back and let the caller judge it on its status code
                // rather than reporting a broken server as a blocked one.
                return new SafeHttpResult($response, $url, $resolvedAddress, $redirectCount);
            }

            $method = $this->methodAfterRedirect($method, $response->status());
            $url = $location;
        }

        throw new TooManyRedirectsException($maximumRedirects);
    }

    /**
     * The method the next hop must use.
     *
     * 307 and 308 exist precisely to carry the original method across a
     * redirect; 301, 302 and 303 are rewritten to GET by every client in
     * practice. Both callers here already issue GET, so this changes nothing
     * today — it keeps the shared helper correct for anything that does not.
     */
    private function methodAfterRedirect(string $method, int $statusCode): string
    {
        return in_array($statusCode, [307, 308], true) ? $method : 'GET';
    }

    /**
     * Try each candidate address in turn until one answers.
     *
     * Only a connection failure moves on to the next address: once a server has
     * responded, its answer is the answer. Every attempt is re-costed against
     * the shared deadline so a host with several dead addresses cannot spend
     * more time than a host with one.
     *
     * @param  Closure(int): PendingRequest  $requestBuilder
     * @param  list<string>  $addresses
     * @return array{0: Response, 1: string} The response and the address that produced it.
     *
     * @throws ConnectionException
     * @throws ResponseTooLargeException
     */
    private function sendToFirstReachableAddress(
        Closure $requestBuilder,
        string $method,
        string $url,
        string $host,
        int $port,
        array $addresses,
        int $maximumBytes,
        float $deadline,
    ): array {
        $attempts = array_slice($addresses, 0, self::MAX_ADDRESS_ATTEMPTS);
        $lastException = null;

        foreach ($attempts as $address) {
            $remainingSeconds = (int) ceil($deadline - microtime(true));

            if ($remainingSeconds < 1) {
                break;
            }

            try {
                return [
                    $this->send(
                        $requestBuilder($remainingSeconds),
                        $method,
                        $url,
                        $host,
                        $port,
                        $address,
                        $maximumBytes,
                    ),
                    $address,
                ];
            } catch (ConnectionException $exception) {
                $lastException = $exception;
            }
        }

        throw $lastException ?? new ConnectionException(
            "The request to [{$url}] timed out before it could be sent.",
        );
    }

    /**
     * Validate a URL and resolve it to the public addresses it may be reached on.
     *
     * @return array{0: string, 1: int, 2: non-empty-list<string>} Host, port, and addresses.
     *
     * @throws UnsafeRequestException
     */
    public function resolveRequestTarget(string $url): array
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            throw UnsafeRequestException::unsupportedTarget($url);
        }

        $scheme = Str::lower((string) ($parts['scheme'] ?? ''));
        $host = Str::of((string) ($parts['host'] ?? ''))->trim('[]')->toString();
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if (
            ! in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || ! in_array($port, self::ALLOWED_PORTS, true)
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw UnsafeRequestException::unsupportedTarget($url);
        }

        $addresses = $this->resolveAddresses($host);

        if ($addresses === []) {
            throw UnsafeRequestException::unresolvableHost($host);
        }

        $unsafeAddress = $this->firstUnsafeAddress($addresses);

        if ($unsafeAddress !== null) {
            throw UnsafeRequestException::nonPublicAddress($host, $unsafeAddress);
        }

        return [$host, $port, $this->preferIpv4($addresses)];
    }

    /**
     * Order addresses IPv4 first.
     *
     * The documented deployment is a Docker bridge network, which has no IPv6
     * egress by default, so trying an AAAA record first would reliably burn a
     * connect timeout before the fallback reached a working A record.
     *
     * @param  non-empty-list<string>  $addresses
     * @return non-empty-list<string>
     */
    private function preferIpv4(array $addresses): array
    {
        $ipv4 = array_values(array_filter(
            $addresses,
            static fn (string $address): bool => ! str_contains($address, ':'),
        ));

        $ipv6 = array_values(array_filter(
            $addresses,
            static fn (string $address): bool => str_contains($address, ':'),
        ));

        /** @var non-empty-list<string> */
        return [...$ipv4, ...$ipv6];
    }

    /**
     * Resolve a (possibly relative) Location header against the URL that
     * produced it. Returns null when the value cannot form an absolute URL.
     */
    public function resolveLocation(string $baseUrl, string $location): ?string
    {
        $location = trim(html_entity_decode($location, ENT_QUOTES | ENT_HTML5));

        if ($location === '' || str_starts_with($location, '#')) {
            return null;
        }

        if (str_starts_with($location, '//')) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME);

            return is_string($scheme) ? "{$scheme}:{$location}" : null;
        }

        if (filter_var($location, FILTER_VALIDATE_URL) !== false) {
            return $location;
        }

        $baseParts = parse_url($baseUrl);

        if (! is_array($baseParts)) {
            return null;
        }

        $scheme = (string) ($baseParts['scheme'] ?? '');
        $host = (string) ($baseParts['host'] ?? '');

        if ($scheme === '' || $host === '') {
            return null;
        }

        $urlHost = str_contains($host, ':') ? "[{$host}]" : $host;
        $portSuffix = isset($baseParts['port']) ? ':'.(int) $baseParts['port'] : '';
        $path = str_starts_with($location, '/')
            ? $location
            : '/'.ltrim($location, '/');

        return "{$scheme}://{$urlHost}{$portSuffix}{$path}";
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
