<?php

use App\Actions\Monitors\NormalizeMonitorUrl;
use App\Rules\SafePublicUrl;

test('safe public URLs pass validation', function (string $url) {
    $failed = false;

    (new SafePublicUrl)->validate('url', $url, function () use (&$failed): void {
        $failed = true;
    });

    expect($failed)->toBeFalse();
})->with([
    'HTTPS hostname' => 'https://example.com/status',
    'HTTP hostname' => 'http://status.example.com/',
    'public IPv4' => 'https://8.8.8.8/',
    'public IPv6' => 'https://[2606:4700:4700::1111]/',
]);

test('unsafe or internal URLs fail validation', function (string $url) {
    $failed = false;

    (new SafePublicUrl)->validate('url', $url, function () use (&$failed): void {
        $failed = true;
    });

    expect($failed)->toBeTrue();
})->with([
    'single-label host' => 'http://intranet/',
    'localhost subdomain' => 'http://api.localhost/',
    'local domain' => 'http://printer.local/',
    'private IPv4' => 'http://192.168.1.20/',
    'link-local IPv4' => 'http://169.254.10.20/',
    'loopback IPv4' => 'http://127.0.0.1/',
    'loopback IPv6' => 'http://[::1]/',
    'unique-local IPv6' => 'http://[fd00::1]/',
    'credentials' => 'https://user:password@example.com/',
    'custom port' => 'https://example.com:8080/',
    'invalid scheme' => 'file:///etc/passwd',
]);

test('monitor URLs are normalized without changing their query', function () {
    $normalized = (new NormalizeMonitorUrl)->handle(
        ' HTTPS://Example.COM:443/status?region=eu#fragment ',
    );

    expect($normalized)->toBe('https://example.com/status?region=eu');
});
