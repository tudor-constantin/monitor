<?php

use App\Actions\Monitors\PersistMonitorCheck;
use App\Contracts\DnsResolver;
use App\Enums\MonitorCheckStatus;
use App\Jobs\CheckMonitor;
use App\Models\Monitor;
use App\Services\Monitoring\MonitorChecker;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('a queued check persists a normalized successful result', function () {
    $monitor = Monitor::factory()->create([
        'url' => 'https://status.example.com/health',
        'expected_status_code' => 204,
        'timeout_seconds' => 8,
    ]);

    $this->mock(DnsResolver::class)
        ->shouldReceive('resolve')
        ->once()
        ->with('status.example.com')
        ->andReturn(['93.184.216.34']);

    Http::fake([
        'https://status.example.com/health' => Http::response('healthy', 204),
    ]);

    $job = new CheckMonitor($monitor);
    $job->handle(app(MonitorChecker::class), app(PersistMonitorCheck::class));

    $check = $monitor->checks()->sole();

    expect($check)
        ->status->toBe(MonitorCheckStatus::Successful)
        ->status_code->toBe(204)
        ->response_time_ms->toBeGreaterThanOrEqual(0)
        ->response_size_bytes->toBe(7)
        ->resolved_ip->toBe('93.184.216.34')
        ->error_type->toBeNull()
        ->error_message->toBeNull();

    expect($monitor->refresh()->last_checked_at)->not->toBeNull();

    $this->actingAs($monitor->user)
        ->get(route('monitors.show', $monitor))
        ->assertOk()
        ->assertSee('Successful')
        ->assertSee('204')
        ->assertSee('7');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://status.example.com/health'
        && $request->hasHeader('User-Agent', 'Monitor/1.0'));
});

test('an unexpected status code is persisted as a failed check', function () {
    $monitor = Monitor::factory()->create([
        'url' => 'https://status.example.com/',
        'expected_status_code' => 200,
    ]);

    $this->mock(DnsResolver::class)
        ->shouldReceive('resolve')
        ->andReturn(['93.184.216.34']);

    Http::fake([
        'https://status.example.com/' => Http::response('Unavailable', 503),
    ]);

    (new CheckMonitor($monitor))
        ->handle(app(MonitorChecker::class), app(PersistMonitorCheck::class));

    $check = $monitor->checks()->sole();

    expect($check)
        ->status->toBe(MonitorCheckStatus::Failed)
        ->status_code->toBe(503)
        ->error_type->toBe('unexpected_status_code')
        ->error_message->toContain('Expected HTTP 200, received 503.');
});

test('connection errors are normalized and persisted', function () {
    $monitor = Monitor::factory()->create([
        'url' => 'https://offline.example.com/',
    ]);

    $this->mock(DnsResolver::class)
        ->shouldReceive('resolve')
        ->andReturn(['93.184.216.34']);

    Http::fake([
        'https://offline.example.com/' => Http::failedConnection(),
    ]);

    (new CheckMonitor($monitor))
        ->handle(app(MonitorChecker::class), app(PersistMonitorCheck::class));

    expect($monitor->checks()->sole())
        ->status->toBe(MonitorCheckStatus::ConnectionError)
        ->status_code->toBeNull()
        ->error_type->toBe('connection_error');
});

test('connection timeouts are normalized and persisted', function () {
    $monitor = Monitor::factory()->create([
        'url' => 'https://slow.example.com/',
    ]);

    $this->mock(DnsResolver::class)
        ->shouldReceive('resolve')
        ->andReturn(['93.184.216.34']);

    Http::fake([
        'https://slow.example.com/' => Http::failedConnection('Operation timed out'),
    ]);

    (new CheckMonitor($monitor))
        ->handle(app(MonitorChecker::class), app(PersistMonitorCheck::class));

    expect($monitor->checks()->sole())
        ->status->toBe(MonitorCheckStatus::Timeout)
        ->error_type->toBe('timeout');
});

test('oversized responses are rejected before they are persisted', function () {
    $monitor = Monitor::factory()->create([
        'url' => 'https://large.example.com/',
    ]);

    $this->mock(DnsResolver::class)
        ->shouldReceive('resolve')
        ->andReturn(['93.184.216.34']);

    Http::fake([
        'https://large.example.com/' => Http::response(str_repeat('a', 1048577)),
    ]);

    (new CheckMonitor($monitor))
        ->handle(app(MonitorChecker::class), app(PersistMonitorCheck::class));

    expect($monitor->checks()->sole())
        ->status->toBe(MonitorCheckStatus::InvalidResponse)
        ->error_type->toBe('response_too_large')
        ->response_size_bytes->toBeNull();
});

test('a destination resolving to any non-global address is blocked before connecting', function (string $unsafeAddress) {
    $monitor = Monitor::factory()->create([
        'url' => 'https://rebinding.example.com/',
    ]);

    $this->mock(DnsResolver::class)
        ->shouldReceive('resolve')
        ->once()
        ->andReturn(['93.184.216.34', $unsafeAddress]);

    Http::fake();

    (new CheckMonitor($monitor))
        ->handle(app(MonitorChecker::class), app(PersistMonitorCheck::class));

    expect($monitor->checks()->sole())
        ->status->toBe(MonitorCheckStatus::Blocked)
        ->resolved_ip->toBe($unsafeAddress)
        ->error_type->toBe('unsafe_ip_address');

    Http::assertNothingSent();
})->with([
    'private address' => '10.0.0.8',
    'shared address space' => '100.64.0.8',
    'benchmarking range' => '198.18.0.8',
    'deprecated relay anycast IPv4' => '192.88.99.8',
    'local-use translation IPv6' => '64:ff9b:1::8',
    'dummy IPv6 prefix' => '100:0:0:1::8',
    'documentation IPv6 allocation' => '3fff::8',
    'unallocated IPv6 block 4000' => '4000::8',
    'segment routing IPv6 allocation' => '5f00::8',
    'unallocated IPv6 block 6000' => '6000::8',
    'deprecated site-local IPv6' => 'fec0::8',
    'reserved high IPv6 block' => 'fe00::8',
]);

test('paused monitors are not checked', function () {
    $monitor = Monitor::factory()->paused()->create();

    Http::fake();

    (new CheckMonitor($monitor))
        ->handle(app(MonitorChecker::class), app(PersistMonitorCheck::class));

    expect($monitor->checks()->count())->toBe(0);
    Http::assertNothingSent();
});

test('monitor checks use the Redis checks queue and a unique monitor key', function () {
    $monitor = Monitor::factory()->create();
    $job = new CheckMonitor($monitor);

    expect($job)
        ->connection->toBe('redis')
        ->queue->toBe('checks')
        ->uniqueId()->toBe((string) $monitor->id)
        ->tries->toBe(3)
        ->backoff->toBe([1, 5, 10]);
});
