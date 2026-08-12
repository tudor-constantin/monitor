<?php

use App\Actions\Monitors\ClaimMonitorCheck;
use App\Actions\Monitors\PersistMonitorCheck;
use App\Contracts\DnsResolver;
use App\Data\MonitorCheckResult;
use App\Enums\MonitorCheckStatus;
use App\Jobs\CheckMonitor;
use App\Models\Monitor;
use App\Services\Monitoring\MonitorChecker;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

function claimableMonitor(): Monitor
{
    $monitor = Monitor::factory()->create(['url' => 'https://status.example.com/health']);

    test()->mock(DnsResolver::class)
        ->shouldReceive('resolve')
        ->with('status.example.com')
        ->andReturn(['93.184.216.34']);

    Http::fake(['*' => Http::response('healthy', 200)]);

    return $monitor;
}

function runClaimedCheck(Monitor $monitor, ?CarbonInterface $epoch = null): void
{
    (new CheckMonitor($monitor, $epoch))->handle(
        app(MonitorChecker::class),
        app(PersistMonitorCheck::class),
        app(ClaimMonitorCheck::class),
    );
}

function claimedResult(): MonitorCheckResult
{
    return new MonitorCheckResult(
        status: MonitorCheckStatus::Successful,
        statusCode: 200,
        responseTimeMs: 10,
        responseSizeBytes: 10,
        resolvedIp: '93.184.216.34',
        errorType: null,
        errorMessage: null,
        checkedAt: now(),
    );
}

test('a run whose claim was taken over writes nothing', function () {
    $monitor = claimableMonitor();
    $claims = app(ClaimMonitorCheck::class);

    $superseded = $claims->acquire($monitor, 300);
    expect($superseded)->not->toBeNull();

    // The overrun the whole finding turns on: this run is still going when its
    // lease lapses and a newer run takes the monitor over.
    $monitor->forceFill(['check_claim_expires_at' => now()->subSecond()])->save();
    expect($claims->acquire($monitor, 300))->not->toBeNull();

    expect(app(PersistMonitorCheck::class)->handle($monitor, claimedResult(), $superseded))->toBeNull()
        ->and($monitor->checks()->count())->toBe(0);
});

test('the run that still owns the monitor writes its result', function () {
    $monitor = claimableMonitor();
    $token = app(ClaimMonitorCheck::class)->acquire($monitor, 300);

    expect(app(PersistMonitorCheck::class)->handle($monitor, claimedResult(), $token))->not->toBeNull()
        ->and($monitor->checks()->count())->toBe(1);
});

test('only one of two concurrent runs records a check', function () {
    $monitor = claimableMonitor();
    $inner = false;

    // The second run is executed from inside the first one's outbound request,
    // so both are genuinely in flight at once -- the state the TTL designs
    // could not prevent, and no longer have to.
    Http::fake(function () use ($monitor, &$inner) {
        if (! $inner) {
            $inner = true;
            runClaimedCheck($monitor);
        }

        return Http::response('healthy', 200);
    });

    runClaimedCheck($monitor);

    expect($inner)->toBeTrue()
        ->and($monitor->checks()->count())->toBe(1);
});

test('a second copy of the same dispatch records nothing', function () {
    $monitor = claimableMonitor();
    $slot = now();

    // Exactly the finding: the queue's unique lock lapsed, a duplicate was
    // enqueued, and both drain. They are copies of one scheduled check, so one
    // result is correct and two is the bug -- two rows a second apart,
    // consecutive_failures moving twice, an incident on one interval's evidence.
    runClaimedCheck($monitor, $slot);
    runClaimedCheck($monitor, $slot);

    expect($monitor->checks()->count())->toBe(1)
        ->and($monitor->fresh()->check_claim_token)->toBeNull();
});

test('the next scheduling slot still records its own check', function () {
    $monitor = claimableMonitor();

    runClaimedCheck($monitor, now());
    runClaimedCheck($monitor, now()->addSeconds($monitor->interval_seconds));

    // Guards against fixing the duplicate by simply refusing everything after
    // the first check.
    expect($monitor->checks()->count())->toBe(2);
});

test('a copy draining out of order behind a newer one is refused', function () {
    $monitor = claimableMonitor();

    runClaimedCheck($monitor, now());
    runClaimedCheck($monitor, now()->subSeconds($monitor->interval_seconds));

    expect($monitor->checks()->count())->toBe(1);
});

test('a retry whose predecessor died before writing still records', function () {
    $monitor = claimableMonitor();
    $slot = now();

    // The first attempt was killed mid-check, so the slot was never spent. Its
    // retry carries the same slot and must not be mistaken for a duplicate.
    app(ClaimMonitorCheck::class)->acquire($monitor, 300);
    $monitor->forceFill(['check_claim_expires_at' => now()->subSecond()])->save();

    runClaimedCheck($monitor, $slot);

    expect($monitor->checks()->count())->toBe(1);
});

test('a crashed run does not hold its monitor past the lease', function () {
    $monitor = claimableMonitor();
    $claims = app(ClaimMonitorCheck::class);

    // Claimed and never released, the way a SIGKILLed worker leaves it.
    expect($claims->acquire($monitor, 300))->not->toBeNull()
        ->and($claims->acquire($monitor, 300))->toBeNull();

    $this->travel(301)->seconds();

    expect($claims->acquire($monitor, 300))->not->toBeNull();
});

test('a superseded run cannot hand back a claim that is no longer its own', function () {
    $monitor = claimableMonitor();
    $claims = app(ClaimMonitorCheck::class);

    $superseded = $claims->acquire($monitor, 300);
    $monitor->forceFill(['check_claim_expires_at' => now()->subSecond()])->save();
    $live = $claims->acquire($monitor, 300);

    // The straggler finishing and tidying up must not strip the claim off the
    // run that took over, or the next dispatch walks straight in beside it.
    $claims->release($monitor, $superseded);

    expect($monitor->fresh()->check_claim_token)->toBe($live)
        ->and($claims->acquire($monitor, 300))->toBeNull();
});

test('a second copy of a spent slot makes no outbound request', function () {
    $monitor = claimableMonitor();
    $slot = now();

    runClaimedCheck($monitor, $slot);
    runClaimedCheck($monitor, $slot);

    // The stated impact of the finding is duplicate outbound checks, so it is
    // not enough for the second copy's result to be discarded after the fact:
    // the request must never leave.
    Http::assertSentCount(1);
    expect($monitor->checks()->count())->toBe(1);
});
