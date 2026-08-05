<?php

use App\Actions\StatusPages\RequestStatusPageSubscription;
use App\Models\StatusPage;
use App\Notifications\ConfirmStatusPageSubscription;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

test('one address cannot be mailed confirmations indefinitely, even from many pages and IPs', function () {
    Notification::fake();
    config(['monitoring.public_subscription_limit_per_email_per_hour' => 2]);

    $action = app(RequestStatusPageSubscription::class);
    $victim = 'victim@example.com';

    // Different status pages and different source addresses: the per-IP bucket
    // never fills, so only the per-email limit can stop this.
    foreach (range(1, 2) as $attempt) {
        $statusPage = StatusPage::factory()->create(['is_public' => true]);
        $action->handle($statusPage, $victim, true, [], "203.0.113.{$attempt}");
    }

    $thirdPage = StatusPage::factory()->create(['is_public' => true]);

    expect(fn () => $action->handle($thirdPage, $victim, true, [], '203.0.113.99'))
        ->toThrow(ValidationException::class);

    Notification::assertSentTimes(ConfirmStatusPageSubscription::class, 2);
});

test('a different address is unaffected by another address hitting its limit', function () {
    Notification::fake();
    config(['monitoring.public_subscription_limit_per_email_per_hour' => 1]);

    $statusPage = StatusPage::factory()->create(['is_public' => true]);
    $action = app(RequestStatusPageSubscription::class);

    $action->handle($statusPage, 'first@example.com', true, [], '203.0.113.1');
    $action->handle($statusPage, 'second@example.com', true, [], '203.0.113.1');

    expect(fn () => $action->handle($statusPage, 'first@example.com', true, [], '203.0.113.1'))
        ->toThrow(ValidationException::class);

    Notification::assertSentTimes(ConfirmStatusPageSubscription::class, 2);
});
