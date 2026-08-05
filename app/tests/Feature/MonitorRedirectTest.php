<?php

use App\Actions\Monitors\PersistMonitorCheck;
use App\Contracts\DnsResolver;
use App\Enums\MonitorCheckStatus;
use App\Enums\MonitorStatus;
use App\Jobs\CheckMonitor;
use App\Models\Monitor;
use App\Services\Monitoring\MonitorChecker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

function runCheck(Monitor $monitor): void
{
    (new CheckMonitor($monitor))
        ->handle(app(MonitorChecker::class), app(PersistMonitorCheck::class));
}

test('an apex to www redirect is followed instead of being reported as a failure', function () {
    $monitor = Monitor::factory()->create([
        'url' => 'https://example.com/',
        'expected_status_code' => 200,
    ]);

    $this->mock(DnsResolver::class)
        ->shouldReceive('resolve')
        ->andReturnUsing(fn (string $host): array => match ($host) {
            'example.com' => ['93.184.216.34'],
            'www.example.com' => ['93.184.216.35'],
            default => [],
        });

    Http::fake([
        'https://example.com/' => Http::response('', 301, ['Location' => 'https://www.example.com/']),
        'https://www.example.com/' => Http::response('welcome', 200),
    ]);

    runCheck($monitor);

    expect($monitor->checks()->sole())
        ->status->toBe(MonitorCheckStatus::Successful)
        ->status_code->toBe(200)
        ->resolved_ip->toBe('93.184.216.35')
        ->error_type->toBeNull();

    expect($monitor->refresh()->status)->toBe(MonitorStatus::Up);
});

test('a relative redirect is resolved against the URL that issued it', function () {
    $monitor = Monitor::factory()->create(['url' => 'https://example.com/old']);

    $this->mock(DnsResolver::class)
        ->shouldReceive('resolve')
        ->andReturn(['93.184.216.34']);

    Http::fake([
        'https://example.com/old' => Http::response('', 302, ['Location' => '/new']),
        'https://example.com/new' => Http::response('here', 200),
    ]);

    runCheck($monitor);

    expect($monitor->checks()->sole()->status)->toBe(MonitorCheckStatus::Successful);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://example.com/new');
});

test('a redirect into a private network is blocked and never connected to', function () {
    $monitor = Monitor::factory()->create(['url' => 'https://example.com/']);

    $this->mock(DnsResolver::class)
        ->shouldReceive('resolve')
        ->andReturnUsing(fn (string $host): array => match ($host) {
            'example.com' => ['93.184.216.34'],
            'internal.example.com' => ['10.0.0.8'],
            default => [],
        });

    Http::fake([
        'https://example.com/' => Http::response('', 302, [
            'Location' => 'https://internal.example.com/admin',
        ]),
    ]);

    runCheck($monitor);

    expect($monitor->checks()->sole())
        ->status->toBe(MonitorCheckStatus::Blocked)
        ->error_type->toBe('unsafe_redirect')
        ->resolved_ip->toBe('10.0.0.8');

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'internal.example.com'));
});

test('a redirect to a non web port is blocked', function () {
    $monitor = Monitor::factory()->create(['url' => 'https://example.com/']);

    $this->mock(DnsResolver::class)
        ->shouldReceive('resolve')
        ->andReturn(['93.184.216.34']);

    Http::fake([
        'https://example.com/' => Http::response('', 302, [
            'Location' => 'https://example.com:2375/containers/json',
        ]),
    ]);

    runCheck($monitor);

    expect($monitor->checks()->sole())
        ->status->toBe(MonitorCheckStatus::Blocked)
        ->error_type->toBe('unsafe_redirect');
});

test('a 3xx we cannot follow is judged on its status code, not reported as blocked', function (int $status, array $headers) {
    $monitor = Monitor::factory()->create([
        'url' => 'https://example.com/',
        'expected_status_code' => 200,
    ]);

    $this->mock(DnsResolver::class)
        ->shouldReceive('resolve')
        ->andReturn(['93.184.216.34']);

    Http::fake(['https://example.com/' => Http::response('', $status, $headers)]);

    runCheck($monitor);

    // A broken or non-navigational 3xx is a broken server, not a security
    // event: calling it "blocked" would send the operator hunting for an SSRF
    // attempt that never happened.
    expect($monitor->checks()->sole())
        ->status->toBe(MonitorCheckStatus::Failed)
        ->status_code->toBe($status)
        ->error_type->toBe('unexpected_status_code');
})->with([
    'not modified' => [304, []],
    'redirect without a location' => [302, []],
    'multiple choices' => [300, []],
    'unusable location' => [302, ['Location' => '#fragment-only']],
]);

test('an endless redirect loop is bounded and reported', function () {
    $monitor = Monitor::factory()->create(['url' => 'https://example.com/loop']);

    $this->mock(DnsResolver::class)
        ->shouldReceive('resolve')
        ->andReturn(['93.184.216.34']);

    Http::fake([
        'https://example.com/loop' => Http::response('', 302, [
            'Location' => 'https://example.com/loop',
        ]),
    ]);

    runCheck($monitor);

    expect($monitor->checks()->sole())
        ->status->toBe(MonitorCheckStatus::InvalidResponse)
        ->error_type->toBe('too_many_redirects');
});

test('an unexpected status after a redirect names the URL that produced it', function () {
    $monitor = Monitor::factory()->create([
        'url' => 'https://example.com/',
        'expected_status_code' => 200,
    ]);

    $this->mock(DnsResolver::class)
        ->shouldReceive('resolve')
        ->andReturn(['93.184.216.34']);

    Http::fake([
        'https://example.com/' => Http::response('', 301, ['Location' => 'https://example.com/gone']),
        'https://example.com/gone' => Http::response('missing', 404),
    ]);

    runCheck($monitor);

    expect($monitor->checks()->sole())
        ->status->toBe(MonitorCheckStatus::Failed)
        ->status_code->toBe(404)
        ->error_message->toContain('https://example.com/gone');
});

test('the next resolved address is tried when the first one refuses the connection', function () {
    $monitor = Monitor::factory()->create(['url' => 'https://example.com/']);

    $this->mock(DnsResolver::class)
        ->shouldReceive('resolve')
        ->andReturn(['93.184.216.34', '93.184.216.35']);

    $attempts = 0;

    Http::fake(function () use (&$attempts) {
        $attempts++;

        if ($attempts === 1) {
            throw new ConnectionException('Connection refused');
        }

        return Http::response('up', 200);
    });

    runCheck($monitor);

    expect($attempts)->toBe(2)
        ->and($monitor->checks()->sole())
        ->status->toBe(MonitorCheckStatus::Successful)
        ->resolved_ip->toBe('93.184.216.35');
});

test('IPv4 addresses are attempted before IPv6 ones', function () {
    $monitor = Monitor::factory()->create(['url' => 'https://example.com/']);

    $this->mock(DnsResolver::class)
        ->shouldReceive('resolve')
        ->andReturn(['2606:2800:220:1:248:1893:25c8:1946', '93.184.216.34']);

    Http::fake(['https://example.com/' => Http::response('up', 200)]);

    runCheck($monitor);

    expect($monitor->checks()->sole()->resolved_ip)->toBe('93.184.216.34');
});
