<?php

use App\Models\Monitor;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

afterEach(function () {
    Carbon::setTestNow();
});

test('stored in-app notifications are actually shown to the user', function () {
    $user = User::factory()->create();
    storeDownNotification($user);

    $this->actingAs($user)
        ->get(route('notifications.index'))
        ->assertOk()
        ->assertSee('Acme website')
        ->assertSee('Down');
});

test('a notification can be marked as read and deleted from the inbox', function () {
    $user = User::factory()->create();
    storeDownNotification($user);

    $notification = $user->notifications()->sole();

    Livewire::actingAs($user)
        ->test('pages::notifications.index')
        ->call('markAsRead', $notification->id);

    expect($user->unreadNotifications()->count())->toBe(0);

    Livewire::actingAs($user)
        ->test('pages::notifications.index')
        ->call('delete', $notification->id);

    expect($user->notifications()->count())->toBe(0);
});

test('marking everything as read clears only the current user', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    storeDownNotification($user);
    storeDownNotification($other);

    Livewire::actingAs($user)
        ->test('pages::notifications.index')
        ->call('markAllAsRead');

    expect($user->unreadNotifications()->count())->toBe(0)
        ->and($other->unreadNotifications()->count())->toBe(1);
});

test('a user cannot touch another user notification', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    storeDownNotification($other);

    $foreignId = $other->notifications()->sole()->id;
    $component = Livewire::actingAs($user)->test('pages::notifications.index');

    // The lookup is scoped to the authenticated user, so someone else's ID
    // simply does not exist as far as this component is concerned.
    expect(fn () => $component->call('delete', $foreignId))
        ->toThrow(ModelNotFoundException::class);

    expect(fn () => $component->call('markAsRead', $foreignId))
        ->toThrow(ModelNotFoundException::class);

    expect($other->notifications()->count())->toBe(1)
        ->and($other->unreadNotifications()->count())->toBe(1);
});

test('the unread filter shows only unread notifications', function () {
    $user = User::factory()->create();

    $readMonitor = Monitor::factory()->for($user)->create(['name' => 'Already read website']);
    $unreadMonitor = Monitor::factory()->for($user)->create(['name' => 'Still unread website']);

    storeDownNotification($user, $readMonitor);
    storeDownNotification($user, $unreadMonitor);

    // Identify the notification by its payload rather than by ordering.
    $user->notifications()
        ->whereJsonContains('data->monitor_id', $readMonitor->id)
        ->sole()
        ->markAsRead();

    Livewire::actingAs($user)
        ->test('pages::notifications.index')
        ->set('filter', 'unread')
        ->assertSee('Still unread website')
        ->assertDontSee('Already read website');
});

test('an unknown filter falls back to showing everything', function () {
    $user = User::factory()->create();
    storeDownNotification($user);

    Livewire::actingAs($user)
        ->withQueryParams(['filter' => 'nonsense'])
        ->test('pages::notifications.index')
        ->assertSet('filter', 'all')
        ->assertSee('Acme website');
});
