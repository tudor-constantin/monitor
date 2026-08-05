<?php

declare(strict_types=1);

namespace App\Actions\Monitors;

use Illuminate\Support\Str;

class NormalizeMonitorUrl
{
    public function handle(string $url): string
    {
        $parts = parse_url(Str::of($url)->trim()->toString());

        if (! is_array($parts)) {
            return $url;
        }

        $scheme = Str::lower($parts['scheme'] ?? '');
        $host = Str::of($parts['host'] ?? '')
            ->trim('[]')
            ->lower()
            ->toString();
        $port = $parts['port'] ?? null;
        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        if (str_contains($host, ':')) {
            $host = '['.$host.']';
        }

        $portSuffix = $port !== null && ! (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))
            ? ':'.$port
            : '';

        return "{$scheme}://{$host}{$portSuffix}".($path === '' ? '/' : $path).$query;
    }
}
