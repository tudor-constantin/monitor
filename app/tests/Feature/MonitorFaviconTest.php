<?php

use App\Contracts\DnsResolver;
use App\Jobs\FetchMonitorFavicon;
use App\Models\Monitor;
use App\Services\Monitoring\MonitorFaviconFetcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Http::preventStrayRequests();
    Storage::fake('public');
});

test('a favicon is downloaded from a public monitor origin and stored locally', function () {
    $monitor = Monitor::factory()->create([
        'url' => 'https://status.example.com/health',
    ]);
    $png = "\x89PNG\r\n\x1a\n".str_repeat('image-data', 4);

    $this->mock(DnsResolver::class)
        ->shouldReceive('resolve')
        ->once()
        ->with('status.example.com')
        ->andReturn(['93.184.216.34']);

    Http::fake([
        'https://status.example.com/favicon.ico' => Http::response($png),
    ]);

    $path = app(MonitorFaviconFetcher::class)->fetch($monitor);

    expect($path)
        ->not->toBeNull()
        ->toEndWith('.png');

    Storage::disk('public')->assertExists($path);

    expect($monitor->refresh())
        ->favicon_path->toBe($path)
        ->favicon_fetched_at->not->toBeNull();
});

test('a favicon declared by the website document is discovered', function () {
    $monitor = Monitor::factory()->create([
        'url' => 'https://status.example.com/health',
    ]);
    $png = "\x89PNG\r\n\x1a\n".str_repeat('image-data', 4);

    $this->mock(DnsResolver::class)
        ->shouldReceive('resolve')
        ->once()
        ->with('status.example.com')
        ->andReturn(['93.184.216.34']);

    Http::fake([
        'https://status.example.com/' => Http::response(
            '<!doctype html><html><head><link rel="icon" href="/assets/icon.png"></head></html>',
            200,
            ['Content-Type' => 'text/html'],
        ),
        'https://status.example.com/assets/icon.png' => Http::response($png),
    ]);

    $path = app(MonitorFaviconFetcher::class)->fetch($monitor);

    expect($path)->not->toBeNull()->toEndWith('.png');
    Storage::disk('public')->assertExists($path);
});

test('favicon discovery follows only revalidated public redirects', function () {
    $monitor = Monitor::factory()->create([
        'url' => 'https://example.com/health',
    ]);
    $png = "\x89PNG\r\n\x1a\n".str_repeat('image-data', 4);

    $dnsResolver = $this->mock(DnsResolver::class);
    $dnsResolver
        ->shouldReceive('resolve')
        ->once()
        ->with('example.com')
        ->andReturn(['93.184.216.34']);
    $dnsResolver
        ->shouldReceive('resolve')
        ->once()
        ->with('www.example.com')
        ->andReturn(['93.184.216.35']);

    Http::fake([
        'https://example.com/' => Http::response('', 302, [
            'Location' => 'https://www.example.com/',
        ]),
        'https://www.example.com/' => Http::response(
            '<html><head><link rel="icon" href="/favicon.png"></head></html>',
            200,
            ['Content-Type' => 'text/html'],
        ),
        'https://www.example.com/favicon.png' => Http::response($png),
    ]);

    $path = app(MonitorFaviconFetcher::class)->fetch($monitor);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://www.example.com/favicon.png');
    expect($path)->not->toBeNull()->toEndWith('.png');
    Storage::disk('public')->assertExists($path);
});

test('a favicon request is blocked when the hostname resolves to a non-global address', function (string $unsafeAddress) {
    $monitor = Monitor::factory()->create([
        'url' => 'https://internal.example.com/',
    ]);

    $this->mock(DnsResolver::class)
        ->shouldReceive('resolve')
        ->once()
        ->andReturn([$unsafeAddress]);

    Http::fake();

    expect(app(MonitorFaviconFetcher::class)->fetch($monitor))->toBeNull();

    Http::assertNothingSent();
    expect($monitor->refresh()->favicon_fetched_at)->not->toBeNull();
})->with([
    'private address' => '10.0.0.10',
    'shared address space' => '100.64.0.10',
    'deprecated relay anycast IPv4' => '192.88.99.10',
    'local-use translation IPv6' => '64:ff9b:1::10',
    'dummy IPv6 prefix' => '100:0:0:1::10',
    'documentation IPv6 allocation' => '3fff::10',
    'unallocated IPv6 block 4000' => '4000::10',
    'segment routing IPv6 allocation' => '5f00::10',
    'unallocated IPv6 block 6000' => '6000::10',
    'deprecated site-local IPv6' => 'fec0::10',
    'reserved high IPv6 block' => 'fe00::10',
]);

test('invalid favicon contents are not stored', function () {
    $monitor = Monitor::factory()->create();

    $this->mock(DnsResolver::class)
        ->shouldReceive('resolve')
        ->once()
        ->andReturn(['93.184.216.34']);

    Http::fake([
        '*' => Http::response('<html>Not an image</html>', 200),
    ]);

    expect(app(MonitorFaviconFetcher::class)->fetch($monitor))->toBeNull();

    Storage::disk('public')->assertDirectoryEmpty('favicons');
});

test('favicon refreshes use a unique Redis maintenance job', function () {
    $monitor = Monitor::factory()->create();
    $job = new FetchMonitorFavicon($monitor);

    expect($job)
        ->connection->toBe('redis')
        ->queue->toBe('maintenance')
        ->uniqueId()->toBe((string) $monitor->id)
        ->tries->toBe(3)
        ->backoff->toBe([5, 30, 120]);
});
