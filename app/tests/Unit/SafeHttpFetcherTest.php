<?php

use App\Contracts\DnsResolver;
use App\Exceptions\Monitoring\ResponseTooLargeException;
use App\Services\Monitoring\IpAddressSafety;
use App\Services\Monitoring\SafeHttpFetcher;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Psr\Http\Message\RequestInterface;

function safeHttpFetcher(DnsResolver $dnsResolver): SafeHttpFetcher
{
    return new SafeHttpFetcher($dnsResolver, new IpAddressSafety);
}

function pendingHttpRequest(callable $handler): PendingRequest
{
    return (new PendingRequest(new Factory))->setHandler($handler);
}

test('resolved addresses are cached per hostname', function () {
    $dnsResolver = Mockery::mock(DnsResolver::class);
    $dnsResolver->shouldReceive('resolve')
        ->once()
        ->with('example.com')
        ->andReturn(['93.184.216.34']);

    $fetcher = safeHttpFetcher($dnsResolver);

    expect($fetcher->resolveAddresses('example.com'))->toBe(['93.184.216.34']);
    expect($fetcher->resolveAddresses('example.com'))->toBe(['93.184.216.34']);
});

test('resetting the cache allows a hostname to be resolved again', function () {
    $dnsResolver = Mockery::mock(DnsResolver::class);
    $dnsResolver->shouldReceive('resolve')
        ->twice()
        ->with('example.com')
        ->andReturn(['93.184.216.34']);

    $fetcher = safeHttpFetcher($dnsResolver);

    $fetcher->resolveAddresses('example.com');
    $fetcher->resetResolvedAddressCache();
    $fetcher->resolveAddresses('example.com');
});

test('firstUnsafeAddress returns null when every address is public', function () {
    $fetcher = safeHttpFetcher(Mockery::mock(DnsResolver::class));

    expect($fetcher->firstUnsafeAddress(['8.8.8.8', '93.184.216.34']))->toBeNull();
});

test('firstUnsafeAddress returns the first non-public address encountered', function () {
    $fetcher = safeHttpFetcher(Mockery::mock(DnsResolver::class));

    expect($fetcher->firstUnsafeAddress(['8.8.8.8', '10.0.0.5', '192.168.1.1']))->toBe('10.0.0.5');
});

test('resolveSafeAddress returns null when DNS resolution yields no addresses', function () {
    $dnsResolver = Mockery::mock(DnsResolver::class);
    $dnsResolver->shouldReceive('resolve')->once()->andReturn([]);

    expect(safeHttpFetcher($dnsResolver)->resolveSafeAddress('unresolvable.example.com'))->toBeNull();
});

test('resolveSafeAddress returns null when any resolved address is not public', function () {
    $dnsResolver = Mockery::mock(DnsResolver::class);
    $dnsResolver->shouldReceive('resolve')->once()->andReturn(['93.184.216.34', '127.0.0.1']);

    expect(safeHttpFetcher($dnsResolver)->resolveSafeAddress('rebinding.example.com'))->toBeNull();
});

test('resolveSafeAddress returns the first address when every resolved address is public', function () {
    $dnsResolver = Mockery::mock(DnsResolver::class);
    $dnsResolver->shouldReceive('resolve')->once()->andReturn(['93.184.216.34', '93.184.216.35']);

    expect(safeHttpFetcher($dnsResolver)->resolveSafeAddress('example.com'))->toBe('93.184.216.34');
});

test('oversized successful response headers are normalized to the stable size exception', function () {
    $request = pendingHttpRequest(new MockHandler([
        new PsrResponse(200, ['Content-Length' => '1025']),
    ]));

    try {
        safeHttpFetcher(Mockery::mock(DnsResolver::class))->send(
            $request,
            'GET',
            'https://example.com/',
            'example.com',
            443,
            '93.184.216.34',
            1024,
        );
    } catch (ResponseTooLargeException $exception) {
        expect($exception->getPrevious())->toBeInstanceOf(ConnectionException::class);

        return;
    }

    $this->fail('The oversized response did not throw ResponseTooLargeException.');
});

test('oversized failed response headers are normalized to the stable size exception', function () {
    $request = pendingHttpRequest(new MockHandler([
        new PsrResponse(413, ['Content-Length' => '1025']),
    ]));

    try {
        safeHttpFetcher(Mockery::mock(DnsResolver::class))->send(
            $request,
            'GET',
            'https://example.com/',
            'example.com',
            443,
            '93.184.216.34',
            1024,
        );
    } catch (ResponseTooLargeException $exception) {
        expect($exception->getPrevious())->toBeInstanceOf(RequestException::class);

        return;
    }

    $this->fail('The oversized response did not throw ResponseTooLargeException.');
});

test('failed responses within the size limit are returned unchanged', function () {
    $request = pendingHttpRequest(new MockHandler([
        new PsrResponse(413, ['Content-Length' => '4'], 'test'),
    ]));

    $response = safeHttpFetcher(Mockery::mock(DnsResolver::class))->send(
        $request,
        'GET',
        'https://example.com/',
        'example.com',
        443,
        '93.184.216.34',
        1024,
    );

    expect($response->status())->toBe(413)
        ->and($response->body())->toBe('test');
});

test('connection failures are rethrown without being classified as oversized responses', function () {
    $guzzleException = new ConnectException(
        'Operation timed out',
        new PsrRequest('GET', 'https://example.com/'),
    );
    /** @param array<string|int, mixed> $options */
    $handler = static fn (RequestInterface $request, array $options): PromiseInterface => Create::rejectionFor($guzzleException);
    $request = pendingHttpRequest($handler);

    try {
        safeHttpFetcher(Mockery::mock(DnsResolver::class))->send(
            $request,
            'GET',
            'https://example.com/',
            'example.com',
            443,
            '93.184.216.34',
            1024,
        );
    } catch (ConnectionException $exception) {
        expect($exception->getPrevious())->toBe($guzzleException);

        return;
    }

    $this->fail('The connection failure was not rethrown as ConnectionException.');
});

test('download progress exceeding the limit throws the stable size exception', function () {
    /** @param array<string|int, mixed> $options */
    $handler = static function (RequestInterface $request, array $options): PromiseInterface {
        $progress = $options['progress'] ?? null;

        if (! is_callable($progress)) {
            throw new LogicException('The progress callback was not configured.');
        }

        $progress(2048.0, 2048.0, 0.0, 0.0);

        return Create::promiseFor(new PsrResponse(200));
    };

    expect(fn () => safeHttpFetcher(Mockery::mock(DnsResolver::class))->send(
        pendingHttpRequest($handler),
        'GET',
        'https://example.com/',
        'example.com',
        443,
        '93.184.216.34',
        1024,
    ))->toThrow(ResponseTooLargeException::class);
});

test('requests disable redirects and pin the validated address', function (
    string $resolvedAddress,
    string $expectedResolveEntry,
) {
    /** @param array<string|int, mixed> $options */
    $handler = static function (RequestInterface $request, array $options) use ($expectedResolveEntry): PromiseInterface {
        expect($options['allow_redirects'] ?? null)->toBeFalse()
            ->and($options['curl'][CURLOPT_RESOLVE] ?? null)->toBe([$expectedResolveEntry]);

        return Create::promiseFor(new PsrResponse(200));
    };

    $response = safeHttpFetcher(Mockery::mock(DnsResolver::class))->send(
        pendingHttpRequest($handler),
        'GET',
        'https://example.com/',
        'example.com',
        443,
        $resolvedAddress,
        1024,
    );

    expect($response->successful())->toBeTrue();
})->with([
    'IPv4' => ['93.184.216.34', 'example.com:443:93.184.216.34'],
    'IPv6' => ['2606:2800:220:1:248:1893:25c8:1946', 'example.com:443:[2606:2800:220:1:248:1893:25c8:1946]'],
]);
