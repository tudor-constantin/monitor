<?php

use App\Actions\StatusPages\RequestStatusPageSubscription;
use App\Events\IncidentOpened;
use App\Events\IncidentResolved;
use App\Listeners\SendStatusPageDownNotifications;
use App\Listeners\SendStatusPageRecoveredNotifications;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\StatusPageSubscription;
use App\Notifications\ConfirmStatusPageSubscription;
use App\Notifications\StatusPageIncidentNotification;
use App\Services\StatusPages\NotifyStatusPageSubscribers;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

test('a published status page presents the public subscription modal', function () {
    $statusPage = StatusPage::factory()->published()->create();
    $monitor = Monitor::factory()->for($statusPage->user)->create([
        'name' => 'Public API',
    ]);
    $statusPage->monitors()->attach($monitor, ['position' => 0]);

    $this->get(route('status-pages.public', $statusPage))
        ->assertOk()
        ->assertSee('Subscribe to updates')
        ->assertSee('Subscribe to specific services')
        ->assertSee('Send confirmation');
});

test('a draft preview does not allow public subscriptions', function () {
    $statusPage = StatusPage::factory()->create();

    $this->actingAs($statusPage->user)
        ->get(route('status-pages.public', $statusPage))
        ->assertOk()
        ->assertSee('Draft preview')
        ->assertDontSee('Subscribe to updates');
});

test('a visitor can request confirmation for all services without exposing subscription state', function () {
    Notification::fake();

    $statusPage = StatusPage::factory()->published()->create();
    $rateLimitKey = 'status-page-subscription:'.$statusPage->id.':'.hash('sha256', '127.0.0.1');
    RateLimiter::clear($rateLimitKey);

    Livewire::test('pages::status-pages.public-show', ['statusPage' => $statusPage])
        ->set('subscriptionEmail', 'Visitor@Example.com')
        ->call('subscribe')
        ->assertHasNoErrors()
        ->assertSet('subscriptionRequested', true)
        ->assertSee('Check your email');

    $subscription = $statusPage->subscriptions()->sole();

    expect($subscription)
        ->email->toBe('visitor@example.com')
        ->verified_at->toBeNull()
        ->pending_subscribed_to_all->toBeTrue()
        ->confirmation_token_hash->not->toBeNull();

    Notification::assertSentOnDemand(
        ConfirmStatusPageSubscription::class,
        fn (
            ConfirmStatusPageSubscription $notification,
            array $channels,
            AnonymousNotifiable $notifiable,
        ): bool => $notification->subscription->is($subscription)
            && $channels === ['mail']
            && $notifiable->routes['mail'] === 'visitor@example.com',
    );

    RateLimiter::clear($rateLimitKey);
});

test('the queued confirmation notification can build its signed route', function () {
    $subscription = StatusPageSubscription::factory()->unverified()->create();
    $notification = new ConfirmStatusPageSubscription($subscription, 'confirmation-token');

    $mailMessage = $notification->toMail(new AnonymousNotifiable);

    expect($mailMessage->actionText)
        ->toBe('Confirm subscription')
        ->and($mailMessage->actionUrl)
        ->toContain(route('status-page-subscriptions.confirm', [
            'subscription' => $subscription,
            'token' => 'confirmation-token',
        ], absolute: false));
});

test('a visitor can confirm a subscription to specific services', function () {
    Notification::fake();

    $statusPage = StatusPage::factory()->published()->create();
    $selectedMonitor = Monitor::factory()->for($statusPage->user)->create();
    $otherMonitor = Monitor::factory()->for($statusPage->user)->create();
    $statusPage->monitors()->attach([
        $selectedMonitor->id => ['position' => 0],
        $otherMonitor->id => ['position' => 1],
    ]);
    $rateLimitKey = 'status-page-subscription:'.$statusPage->id.':'.hash('sha256', '203.0.113.10');
    RateLimiter::clear($rateLimitKey);

    $subscription = app(RequestStatusPageSubscription::class)->handle(
        $statusPage,
        'specific@example.com',
        false,
        [$selectedMonitor->id],
        '203.0.113.10',
    );
    $token = null;

    Notification::assertSentOnDemand(
        ConfirmStatusPageSubscription::class,
        function (ConfirmStatusPageSubscription $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        },
    );

    expect($token)->toBeString();

    $confirmationUrl = URL::temporarySignedRoute(
        'status-page-subscriptions.confirm',
        now()->addHour(),
        ['subscription' => $subscription, 'token' => $token],
    );

    $this->get($confirmationUrl)
        ->assertRedirect(route('status-pages.public', $statusPage))
        ->assertSessionHas('subscription_status');

    $subscription->refresh();

    expect($subscription)
        ->verified_at->not->toBeNull()
        ->subscribed_to_all->toBeFalse()
        ->confirmation_token_hash->toBeNull()
        ->and($subscription->monitors()->sole()->is($selectedMonitor))->toBeTrue();

    RateLimiter::clear($rateLimitKey);
});

test('subscription confirmation requires a valid signed link and current token', function () {
    $subscription = StatusPageSubscription::factory()->unverified()->create();

    $this->get(route('status-page-subscriptions.confirm', [
        'subscription' => $subscription,
        'token' => 'pending-token',
    ]))->assertForbidden();

    $signedUrl = URL::temporarySignedRoute(
        'status-page-subscriptions.confirm',
        now()->addHour(),
        ['subscription' => $subscription, 'token' => 'incorrect-token'],
    );

    $this->get($signedUrl)->assertForbidden();
});

test('verified subscribers receive only relevant public incident notifications', function () {
    Notification::fake();

    $statusPage = StatusPage::factory()->published()->create();
    $affectedMonitor = Monitor::factory()->for($statusPage->user)->create();
    $otherMonitor = Monitor::factory()->for($statusPage->user)->create();
    $statusPage->monitors()->attach([
        $affectedMonitor->id => ['position' => 0],
        $otherMonitor->id => ['position' => 1],
    ]);

    $allServices = StatusPageSubscription::factory()->for($statusPage)->create([
        'email' => 'all@example.com',
        'subscribed_to_all' => true,
    ]);
    $specificService = StatusPageSubscription::factory()->for($statusPage)->create([
        'email' => 'specific@example.com',
        'subscribed_to_all' => false,
    ]);
    $specificService->monitors()->attach($affectedMonitor);
    $irrelevantService = StatusPageSubscription::factory()->for($statusPage)->create([
        'email' => 'irrelevant@example.com',
        'subscribed_to_all' => false,
    ]);
    $irrelevantService->monitors()->attach($otherMonitor);
    StatusPageSubscription::factory()->unverified()->for($statusPage)->create([
        'email' => 'unverified@example.com',
    ]);

    $incident = Incident::factory()->for($affectedMonitor)->create();

    app(NotifyStatusPageSubscribers::class)->handle($incident, false);

    Notification::assertSentOnDemandTimes(StatusPageIncidentNotification::class, 2);
    Notification::assertSentOnDemand(
        StatusPageIncidentNotification::class,
        fn (
            StatusPageIncidentNotification $notification,
            array $channels,
            AnonymousNotifiable $notifiable,
        ): bool => $notification->subscription->is($allServices)
            && $notifiable->routes['mail'] === 'all@example.com',
    );
    Notification::assertSentOnDemand(
        StatusPageIncidentNotification::class,
        fn (
            StatusPageIncidentNotification $notification,
            array $channels,
            AnonymousNotifiable $notifiable,
        ): bool => $notification->subscription->is($specificService)
            && $notifiable->routes['mail'] === 'specific@example.com',
    );
});

test('a signed unsubscribe link removes the subscription', function () {
    $subscription = StatusPageSubscription::factory()->create();
    $statusPage = $subscription->statusPage;
    $unsubscribeUrl = URL::signedRoute('status-page-subscriptions.unsubscribe', [
        'subscription' => $subscription,
    ]);

    $this->get($unsubscribeUrl)
        ->assertRedirect(route('status-pages.public', $statusPage))
        ->assertSessionHas('subscription_status');

    $this->assertModelMissing($subscription);
});

test('expired unverified subscription requests are pruned without removing active subscribers', function () {
    $expiredRequest = StatusPageSubscription::factory()->unverified()->create([
        'confirmation_requested_at' => now()->subDays(2),
    ]);
    $activeSubscription = StatusPageSubscription::factory()->create();

    $this->artisan('model:prune', [
        '--model' => [StatusPageSubscription::class],
    ])->assertSuccessful();

    $this->assertModelMissing($expiredRequest);
    $this->assertModelExists($activeSubscription);
});

test('public subscription requests are rate limited per status page and address', function () {
    Notification::fake();
    config()->set('monitoring.public_subscription_limit_per_hour', 1);

    $statusPage = StatusPage::factory()->published()->create();
    $ipAddress = '203.0.113.50';
    $rateLimitKey = 'status-page-subscription:'.$statusPage->id.':'.hash('sha256', $ipAddress);
    RateLimiter::clear($rateLimitKey);

    app(RequestStatusPageSubscription::class)->handle(
        $statusPage,
        'first@example.com',
        true,
        [],
        $ipAddress,
    );

    expect(fn () => app(RequestStatusPageSubscription::class)->handle(
        $statusPage,
        'second@example.com',
        true,
        [],
        $ipAddress,
    ))->toThrow(ValidationException::class);

    RateLimiter::clear($rateLimitKey);
});

test('public incident listeners and notifications use the Redis notifications queue', function () {
    Event::fake();

    Event::assertListening(IncidentOpened::class, SendStatusPageDownNotifications::class);
    Event::assertListening(IncidentResolved::class, SendStatusPageRecoveredNotifications::class);

    $incident = Incident::factory()->create();
    $subscription = StatusPageSubscription::factory()->create();

    foreach ([
        new ConfirmStatusPageSubscription($subscription, 'token'),
        new StatusPageIncidentNotification($incident, $subscription, false),
    ] as $notification) {
        expect($notification)
            ->toBeInstanceOf(ShouldQueue::class)
            ->toBeInstanceOf(ShouldBeEncrypted::class)
            ->connection->toBe('redis')
            ->and($notification->viaQueues())->toBe(['mail' => 'notifications']);
    }
});
