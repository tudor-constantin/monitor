<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Exceptions\Monitoring\ResponseTooLargeException;
use App\Models\Monitor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class MonitorFaviconFetcher
{
    private const MAX_IMAGE_BYTES = 262144;

    private const MAX_DOCUMENT_BYTES = 524288;

    private const MAX_REDIRECTS = 3;

    private const MAX_DISCOVERED_ICONS = 5;

    public function __construct(
        private readonly SafeHttpFetcher $safeHttpFetcher,
    ) {}

    public function fetch(Monitor $monitor): ?string
    {
        $this->safeHttpFetcher->resetResolvedAddressCache();
        $origin = $this->originFor($monitor->url);

        if ($origin === null) {
            return $this->recordAttempt($monitor);
        }

        $candidates = $this->discoverIconUrls($origin);
        $candidates[] = "{$origin}/favicon.ico";

        foreach (array_values(array_unique($candidates)) as $faviconUrl) {
            $response = $this->request($faviconUrl, self::MAX_IMAGE_BYTES, true);

            if ($response === null || ! $response->successful()) {
                continue;
            }

            $body = $response->body();
            $extension = $this->detectExtension($body);

            if ($extension !== null) {
                return $this->store($monitor, $body, $extension);
            }
        }

        return $this->recordAttempt($monitor);
    }

    /**
     * @return list<string>
     */
    private function discoverIconUrls(string $origin): array
    {
        $documentUrl = "{$origin}/";
        $response = $this->request($documentUrl, self::MAX_DOCUMENT_BYTES, false, $documentUrl);

        if ($response === null || ! $response->successful()) {
            return [];
        }

        $body = $response->body();

        if (! Str::contains(Str::lower($response->header('Content-Type')), ['html', 'xhtml'])
            && ! Str::contains(Str::lower(Str::substr($body, 0, 256)), ['<html', '<!doctype'])) {
            return [];
        }

        $document = new \DOMDocument;
        $previousUseInternalErrors = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML($body, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseInternalErrors);

        if (! $loaded) {
            return [];
        }

        $candidates = [];

        foreach ($document->getElementsByTagName('link') as $link) {
            $rel = Str::lower($link->getAttribute('rel'));
            $href = trim($link->getAttribute('href'));

            if ($href === '' || ! preg_match('/(?:^|\\s)(?:shortcut icon|icon|apple-touch-icon)(?:\\s|$)/i', $rel)) {
                continue;
            }

            $resolvedUrl = $this->resolveUrl($documentUrl, $href);

            if ($resolvedUrl !== null) {
                $candidates[] = $resolvedUrl;
            }

            if (count($candidates) >= self::MAX_DISCOVERED_ICONS) {
                break;
            }
        }

        return $candidates;
    }

    private function store(Monitor $monitor, string $body, string $extension): string
    {
        $path = sprintf(
            'favicons/monitor-%d-%s.%s',
            $monitor->getKey(),
            hash('xxh3', $monitor->url),
            $extension,
        );

        if (! Storage::disk('public')->put($path, $body)) {
            throw new RuntimeException('The favicon could not be stored.');
        }

        if ($monitor->favicon_path !== null && $monitor->favicon_path !== $path) {
            Storage::disk('public')->delete($monitor->favicon_path);
        }

        $monitor->forceFill([
            'favicon_path' => $path,
            'favicon_fetched_at' => now(),
        ])->save();

        return $path;
    }

    private function request(
        string $url,
        int $maximumBytes,
        bool $acceptImage,
        ?string &$effectiveUrl = null,
    ): ?Response {
        for ($redirects = 0; $redirects <= self::MAX_REDIRECTS; $redirects++) {
            $requestDetails = $this->safeRequestDetails($url);

            if ($requestDetails === null) {
                return null;
            }

            [$host, $port, $resolvedAddress] = $requestDetails;

            try {
                $response = $this->safeHttpFetcher->send(
                    Http::connectTimeout(3)
                        ->timeout(8)
                        ->withUserAgent('Monitor/1.0')
                        ->accept($acceptImage
                            ? 'image/png,image/jpeg,image/gif,image/webp,image/x-icon,image/vnd.microsoft.icon'
                            : 'text/html,application/xhtml+xml'),
                    'GET',
                    $url,
                    $host,
                    $port,
                    $resolvedAddress,
                    $maximumBytes,
                );
            } catch (ConnectionException|ResponseTooLargeException) {
                return null;
            }

            if (strlen($response->body()) > $maximumBytes) {
                return null;
            }

            if (! $response->redirect()) {
                $effectiveUrl = $url;

                return $response;
            }

            $location = $response->header('Location');
            $redirectUrl = $this->resolveUrl($url, $location);

            if ($redirectUrl === null) {
                return null;
            }

            $url = $redirectUrl;
        }

        return null;
    }

    /**
     * @return array{0: string, 1: int, 2: string}|null Host, port, and the resolved, validated public address.
     */
    private function safeRequestDetails(string $url): ?array
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            return null;
        }

        $scheme = Str::lower((string) ($parts['scheme'] ?? ''));
        $host = Str::of((string) ($parts['host'] ?? ''))->trim('[]')->toString();
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if (! in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || ! in_array($port, [80, 443], true)
            || isset($parts['user'])
            || isset($parts['pass'])) {
            return null;
        }

        $resolvedAddress = $this->safeHttpFetcher->resolveSafeAddress($host);

        if ($resolvedAddress === null) {
            return null;
        }

        return [$host, $port, $resolvedAddress];
    }

    private function originFor(string $url): ?string
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            return null;
        }

        $scheme = Str::lower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        $urlHost = str_contains($host, ':') ? "[{$host}]" : $host;
        $portSuffix = isset($parts['port']) ? ':'.(int) $parts['port'] : '';

        return "{$scheme}://{$urlHost}{$portSuffix}";
    }

    private function resolveUrl(string $baseUrl, string $location): ?string
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

    private function recordAttempt(Monitor $monitor): ?string
    {
        $monitor->forceFill(['favicon_fetched_at' => now()])->save();

        return $monitor->favicon_path;
    }

    private function detectExtension(string $contents): ?string
    {
        if (str_starts_with($contents, "\x89PNG\r\n\x1a\n")) {
            return 'png';
        }

        if (str_starts_with($contents, "\xff\xd8\xff")) {
            return 'jpg';
        }

        if (str_starts_with($contents, 'GIF87a') || str_starts_with($contents, 'GIF89a')) {
            return 'gif';
        }

        if (str_starts_with($contents, 'RIFF') && substr($contents, 8, 4) === 'WEBP') {
            return 'webp';
        }

        if (
            str_starts_with($contents, "\x00\x00\x01\x00")
            || str_starts_with($contents, "\x00\x00\x02\x00")
        ) {
            return 'ico';
        }

        return null;
    }
}
