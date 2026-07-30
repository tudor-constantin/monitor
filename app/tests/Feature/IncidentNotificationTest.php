<?php

use App\Events\IncidentOpened;
use App\Events\IncidentResolved;
use App\Listeners\SendMonitorDownNotification;
use App\Listeners\SendMonitorRecoveredNotification;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\User;
use App\Notifications\MonitorDownNotification;
use App\Notifications\MonitorRecoveredNotification;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

test('incident events are connected to their notification listeners', function () {
    Event::fake();

    Event::assertListening(IncidentOpened::class, SendMonitorDownNotification::class);
    Event::assertListening(IncidentResolved::class, SendMonitorRecoveredNotification::class);
});

test('an outage sends one queued notification through the enabled channels', function () {
    Notification::fake();

    $user = User::factory()->create();
    $monitor = Monitor::factory()->for($user)->create();
    $incident = Incident::factory()->for($monitor)->create();

    (new SendMonitorDownNotification)->handle(new IncidentOpened($incident));

    Notification::assertSentTo(
        $user,
        MonitorDownNotification::class,
        fn (MonitorDownNotification $notification, array $channels): bool => $notification->incident->is($incident)
            && $channels === ['database', 'mail'],
    );
    Notification::assertSentTimes(MonitorDownNotification::class, 1);
});

test('a recovery sends one queued notification through the enabled channels', function () {
    Notification::fake();

    $user = User::factory()->create();
    $monitor = Monitor::factory()->for($user)->create();
    $incident = Incident::factory()->resolved()->for($monitor)->create();

    (new SendMonitorRecoveredNotification)->handle(new IncidentResolved($incident));

    Notification::assertSentTo(
        $user,
        MonitorRecoveredNotification::class,
        fn (MonitorRecoveredNotification $notification, array $channels): bool => $notification->incident->is($incident)
            && $channels === ['database', 'mail'],
    );
    Notification::assertSentTimes(MonitorRecoveredNotification::class, 1);
});

test('notification preferences control delivery channels', function () {
    $user = User::factory()->create([
        'email_notifications_enabled' => false,
        'database_notifications_enabled' => true,
    ]);
    $incident = Incident::factory()
        ->for(Monitor::factory()->for($user))
        ->create()
        ->load('monitor');

    expect((new MonitorDownNotification($incident))->via($user))
        ->toBe(['database']);

    $user->update(['database_notifications_enabled' => false]);

    expect((new MonitorDownNotification($incident))->via($user->refresh()))
        ->toBe([]);
});

test('incident notifications use the Redis notifications queue', function () {
    $incident = Incident::factory()->create();

    foreach ([
        new MonitorDownNotification($incident),
        new MonitorRecoveredNotification($incident),
    ] as $notification) {
        expect($notification)
            ->toBeInstanceOf(ShouldQueue::class)
            ->toBeInstanceOf(ShouldBeEncrypted::class)
            ->connection->toBe('redis')
            ->and($notification->viaQueues())->toBe([
                'database' => 'notifications',
                'mail' => 'notifications',
            ]);
    }
});

test('a database notification stores normalized incident data', function () {
    $user = User::factory()->create([
        'email_notifications_enabled' => false,
        'database_notifications_enabled' => true,
    ]);
    $monitor = Monitor::factory()->for($user)->create(['name' => 'Status API']);
    $incident = Incident::factory()->for($monitor)->create()->load('monitor');

    $user->notifyNow(new MonitorDownNotification($incident), ['database']);

    $storedNotification = $user->notifications()->sole();

    expect($storedNotification)
        ->type->toBe('monitor-down')
        ->read_at->toBeNull()
        ->and($storedNotification->data)
        ->toMatchArray([
            'incident_id' => $incident->id,
            'monitor_id' => $monitor->id,
            'monitor_name' => 'Status API',
            'status' => 'down',
        ]);
});

test('incident emails contain monitor-specific content and links', function () {
    $user = User::factory()->create();
    $monitor = Monitor::factory()->for($user)->create([
        'name' => 'Checkout',
        'url' => 'https://checkout.example.com/',
    ]);
    $openIncident = Incident::factory()->for($monitor)->create()->load('monitor');
    $resolvedIncident = Incident::factory()
        ->resolved()
        ->for($monitor)
        ->create()
        ->load('monitor');

    $downMail = (new MonitorDownNotification($openIncident))->toMail($user);
    $recoveryMail = (new MonitorRecoveredNotification($resolvedIncident))->toMail($user);

    expect($downMail)
        ->subject->toBe('Website down: Checkout')
        ->actionText->toBe('View website')
        ->actionUrl->toBe(route('monitors.show', $monitor))
        ->and($downMail->introLines)->toContain('Checkout has failed two consecutive checks.')
        ->and($recoveryMail)
        ->subject->toBe('Website recovered: Checkout')
        ->actionText->toBe('View website')
        ->actionUrl->toBe(route('monitors.show', $monitor))
        ->and($recoveryMail->introLines)->toContain('Checkout is responding successfully again.');
});
