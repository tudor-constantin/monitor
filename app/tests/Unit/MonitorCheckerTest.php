<?php

use App\Enums\MonitorCheckStatus;
use App\Exceptions\Monitoring\UnsafeRequestException;
use App\Models\Monitor;
use App\Services\Monitoring\MonitorChecker;
use App\Services\Monitoring\SafeHttpFetcher;

test('each monitor check starts with a fresh DNS resolution cache', function () {
    $safeHttpFetcher = Mockery::mock(SafeHttpFetcher::class);
    $safeHttpFetcher->shouldReceive('resetResolvedAddressCache')->twice();
    $safeHttpFetcher->shouldReceive('resolveRequestTarget')
        ->twice()
        ->andThrow(UnsafeRequestException::unsupportedTarget('not-a-valid-url'));

    $monitorChecker = new MonitorChecker($safeHttpFetcher);
    $monitor = new Monitor(['url' => 'not-a-valid-url']);

    $monitorChecker->check($monitor);
    $monitorChecker->check($monitor);
});

test('an unusable monitor URL is blocked before any connection is attempted', function () {
    $safeHttpFetcher = Mockery::mock(SafeHttpFetcher::class);
    $safeHttpFetcher->shouldReceive('resetResolvedAddressCache')->once();
    $safeHttpFetcher->shouldReceive('resolveRequestTarget')
        ->once()
        ->andThrow(UnsafeRequestException::unsupportedTarget('not-a-valid-url'));
    $safeHttpFetcher->shouldNotReceive('sendFollowingRedirects');

    $result = (new MonitorChecker($safeHttpFetcher))->check(
        new Monitor(['url' => 'not-a-valid-url']),
    );

    expect($result->status)->toBe(MonitorCheckStatus::Blocked)
        ->and($result->errorType)->toBe('unsafe_request_target')
        ->and($result->statusCode)->toBeNull()
        ->and($result->resolvedIp)->toBeNull();
});
