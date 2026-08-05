<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Data\SafeHttpResult;
use App\Exceptions\Monitoring\ResponseTooLargeException;
use App\Exceptions\Monitoring\TooManyRedirectsException;
use App\Exceptions\Monitoring\UnsafeRequestException;
use App\Models\Monitor;
use Illuminate\Http\Client\ConnectionException;
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

    private const MAX_TOTAL_SECONDS = 20;

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

        $deadline = microtime(true) + self::MAX_TOTAL_SECONDS;

        $candidates = $this->discoverIconUrls($origin, $deadline);
        $candidates[] = "{$origin}/favicon.ico";

        foreach (array_values(array_unique($candidates)) as $faviconUrl) {
            $result = $this->request($faviconUrl, self::MAX_IMAGE_BYTES, true, $deadline);

            if ($result === null || ! $result->response->successful()) {
                continue;
            }

            $body = $result->response->body();
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
    private function discoverIconUrls(string $origin, float $deadline): array
    {
        $result = $this->request("{$origin}/", self::MAX_DOCUMENT_BYTES, false, $deadline);

        if ($result === null || ! $result->response->successful()) {
            return [];
        }

        // Relative icon hrefs resolve against the URL the document was actually
        // served from, which may differ from the origin after a redirect.
        $documentUrl = $result->effectiveUrl;
        $response = $result->response;
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

            $resolvedUrl = $this->safeHttpFetcher->resolveLocation($documentUrl, $href);

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

    /**
     * Fetch $url, following redirects. Favicon discovery is best effort, so
     * every failure mode collapses to null and the caller moves on to the next
     * candidate.
     */
    private function request(
        string $url,
        int $maximumBytes,
        bool $acceptImage,
        float $deadline,
    ): ?SafeHttpResult {
        try {
            $result = $this->safeHttpFetcher->sendFollowingRedirects(
                fn (int $timeoutSeconds) => Http::connectTimeout(min(3, $timeoutSeconds))
                    ->timeout(min(8, $timeoutSeconds))
                    ->withUserAgent('Monitor/1.0')
                    ->accept($acceptImage
                        ? 'image/png,image/jpeg,image/gif,image/webp,image/x-icon,image/vnd.microsoft.icon'
                        : 'text/html,application/xhtml+xml'),
                'GET',
                $url,
                $maximumBytes,
                self::MAX_REDIRECTS,
                $deadline,
            );
        } catch (ConnectionException|ResponseTooLargeException|TooManyRedirectsException|UnsafeRequestException) {
            return null;
        }

        return strlen($result->response->body()) > $maximumBytes ? null : $result;
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
