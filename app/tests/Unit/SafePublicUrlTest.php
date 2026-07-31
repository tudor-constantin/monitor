<?php

use App\Actions\Monitors\NormalizeMonitorUrl;
use App\Rules\SafePublicUrl;
use App\Services\Monitoring\IpAddressSafety;

test('only globally routable IP addresses are considered public', function (string $ipAddress, bool $isPublic) {
    expect((new IpAddressSafety)->isPublic($ipAddress))->toBe($isPublic);
})->with([
    'public IPv4' => ['8.8.8.8', true],
    'public IPv6' => ['2606:4700:4700::1111', true],
    'private IPv4' => ['10.0.0.1', false],
    'shared address space' => ['100.64.0.1', false],
    'benchmarking range' => ['198.18.0.1', false],
    'documentation IPv4' => ['192.0.2.1', false],
    'deprecated relay anycast IPv4' => ['192.88.99.1', false],
    'multicast IPv4' => ['224.0.0.1', false],
    'loopback IPv6' => ['::1', false],
    'unique-local IPv6' => ['fd00::1', false],
    'documentation IPv6' => ['2001:db8::1', false],
    'local-use translation IPv6' => ['64:ff9b:1::1', false],
    'dummy IPv6 prefix' => ['100:0:0:1::1', false],
    'documentation IPv6 allocation' => ['3fff::1', false],
    'unallocated IPv6 block 4000' => ['4000::1', false],
    'segment routing IPv6 allocation' => ['5f00::1', false],
    'unallocated IPv6 block 6000' => ['6000::1', false],
    'deprecated site-local IPv6' => ['fec0::1', false],
    'reserved high IPv6 block' => ['fe00::1', false],
    'multicast IPv6' => ['ff02::1', false],
]);

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
    'shared address IPv4' => 'http://100.64.0.1/',
    'benchmarking IPv4' => 'http://198.18.0.1/',
    'documentation IPv4' => 'http://192.0.2.1/',
    'deprecated relay anycast IPv4' => 'http://192.88.99.1/',
    'loopback IPv6' => 'http://[::1]/',
    'unique-local IPv6' => 'http://[fd00::1]/',
    'documentation IPv6' => 'http://[2001:db8::1]/',
    'local-use translation IPv6' => 'http://[64:ff9b:1::1]/',
    'dummy IPv6 prefix' => 'http://[100:0:0:1::1]/',
    'documentation IPv6 allocation' => 'http://[3fff::1]/',
    'unallocated IPv6 block 4000' => 'http://[4000::1]/',
    'segment routing IPv6 allocation' => 'http://[5f00::1]/',
    'unallocated IPv6 block 6000' => 'http://[6000::1]/',
    'deprecated site-local IPv6' => 'http://[fec0::1]/',
    'reserved high IPv6 block' => 'http://[fe00::1]/',
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
