<?php

use App\Models\Monitor;
use App\Services\Monitoring\MonitorChecker;
use App\Services\Monitoring\SafeHttpFetcher;

test('each monitor check starts with a fresh DNS resolution cache', function () {
    $safeHttpFetcher = Mockery::mock(SafeHttpFetcher::class);
    $safeHttpFetcher->shouldReceive('resetResolvedAddressCache')->twice();

    $monitorChecker = new MonitorChecker($safeHttpFetcher);
    $monitor = new Monitor(['url' => 'not-a-valid-url']);

    $monitorChecker->check($monitor);
    $monitorChecker->check($monitor);
});
