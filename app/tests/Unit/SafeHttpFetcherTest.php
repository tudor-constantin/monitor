<?php

use App\Contracts\DnsResolver;
use App\Exceptions\Monitoring\ResponseTooLargeException;
use App\Exceptions\Monitoring\UnsafeRequestException;
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

test('resolveRequestTarget rejects a URL that is not plain HTTP or HTTPS on a web port', function (string $url) {
    $dnsResolver = Mockery::mock(DnsResolver::class);
    $dnsResolver->shouldNotReceive('resolve');

    expect(fn () => safeHttpFetcher($dnsResolver)->resolveRequestTarget($url))
        ->toThrow(UnsafeRequestException::class);
})->with([
    'unsupported scheme' => 'ftp://example.com/',
    'no host' => 'https:///path',
    'non web port' => 'https://example.com:2375/',
    'embedded credentials' => 'https://user:secret@example.com/',
]);

test('resolveRequestTarget reports an unresolvable host distinctly from an unsafe one', function () {
    $dnsResolver = Mockery::mock(DnsResolver::class);
    $dnsResolver->shouldReceive('resolve')->once()->andReturn([]);

    try {
        safeHttpFetcher($dnsResolver)->resolveRequestTarget('https://unresolvable.example.com/');
        $this->fail('An unresolvable host should not produce a request target.');
    } catch (UnsafeRequestException $exception) {
        expect($exception->errorType())->toBe('dns_resolution_failed')
            ->and($exception->address())->toBeNull();
    }
});

test('resolveRequestTarget rejects a host with any non-public address and names it', function () {
    $dnsResolver = Mockery::mock(DnsResolver::class);
    $dnsResolver->shouldReceive('resolve')->once()->andReturn(['93.184.216.34', '10.0.0.8']);

    try {
        safeHttpFetcher($dnsResolver)->resolveRequestTarget('https://rebinding.example.com/');
        $this->fail('A host resolving to a private address should be rejected.');
    } catch (UnsafeRequestException $exception) {
        expect($exception->errorType())->toBe('unsafe_ip_address')
            ->and($exception->address())->toBe('10.0.0.8');
    }
});

test('resolveRequestTarget returns every public address, IPv4 first', function () {
    $dnsResolver = Mockery::mock(DnsResolver::class);
    $dnsResolver->shouldReceive('resolve')
        ->once()
        ->andReturn(['2606:2800:220:1:248:1893:25c8:1946', '93.184.216.34']);

    [$host, $port, $addresses] = safeHttpFetcher($dnsResolver)
        ->resolveRequestTarget('https://example.com/');

    expect($host)->toBe('example.com')
        ->and($port)->toBe(443)
        ->and($addresses)->toBe(['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946']);
});

test('a redirect rewrites the method to GET, except for 307 and 308', function (
    int $status,
    string $expectedSecondMethod,
) {
    $dnsResolver = Mockery::mock(DnsResolver::class);
    $dnsResolver->shouldReceive('resolve')->andReturn(['93.184.216.34']);

    $methods = [];
    $handler = static function (RequestInterface $request) use (&$methods, $status): PromiseInterface {
        $methods[] = $request->getMethod();

        return Create::promiseFor(count($methods) === 1
            ? new PsrResponse($status, ['Location' => 'https://example.com/next'])
            : new PsrResponse(200));
    };

    safeHttpFetcher($dnsResolver)->sendFollowingRedirects(
        fn (int $timeout): PendingRequest => pendingHttpRequest($handler),
        'POST',
        'https://example.com/',
        1024,
        3,
        microtime(true) + 30,
    );

    expect($methods)->toBe(['POST', $expectedSecondMethod]);
})->with([
    '301 rewrites to GET' => [301, 'GET'],
    '302 rewrites to GET' => [302, 'GET'],
    '303 rewrites to GET' => [303, 'GET'],
    '307 preserves the method' => [307, 'POST'],
    '308 preserves the method' => [308, 'POST'],
]);

test('a chain that is already past its deadline is not even resolved', function () {
    $dnsResolver = Mockery::mock(DnsResolver::class);
    // Resolving costs a DNS lookup that cannot be given a timeout, so an
    // expired budget must stop the chain before that point.
    $dnsResolver->shouldNotReceive('resolve');

    expect(fn () => safeHttpFetcher($dnsResolver)->sendFollowingRedirects(
        fn (int $timeout): PendingRequest => pendingHttpRequest(
            static fn (): PromiseInterface => Create::promiseFor(new PsrResponse(200)),
        ),
        'GET',
        'https://example.com/',
        1024,
        3,
        microtime(true) - 1,
    ))->toThrow(ConnectionException::class);
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
