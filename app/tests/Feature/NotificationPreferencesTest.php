<?php

use App\Models\User;
use Livewire\Livewire;

test('guests cannot manage notification preferences', function () {
    $this->get(route('notifications.edit'))
        ->assertRedirect(route('login'));
});

test('a verified user can update notification preferences', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.notifications')
        ->assertSet('emailNotificationsEnabled', true)
        ->assertSet('databaseNotificationsEnabled', true)
        ->set('emailNotificationsEnabled', false)
        ->set('databaseNotificationsEnabled', false)
        ->call('save')
        ->assertHasNoErrors();

    expect($user->refresh())
        ->email_notifications_enabled->toBeFalse()
        ->database_notifications_enabled->toBeFalse();
});

test('notification preferences live in settings and link to the inbox', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('notifications.edit'))
        ->assertOk()
        ->assertSee('Save preferences')
        ->assertSee('Notifications');

    expect(route('notifications.edit', absolute: false))->toBe('/settings/notifications');
});

test('every settings screen carries the same page heading', function (string $route) {
    $user = User::factory()->create();

    // The heading lives in the shared partial, so a new settings screen that
    // forgets to include it renders without any page title at all -- which is
    // exactly what happened when notification preferences moved in here.
    $this->actingAs($user)
        // Security sits behind password confirmation; the others ignore this.
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route($route))
        ->assertOk()
        ->assertSee('Manage your profile and account settings');
})->with([
    'profile' => 'profile.edit',
    'notifications' => 'notifications.edit',
    'security' => 'security.edit',
]);

test('the workspace notifications entry opens the inbox rather than the preferences', function () {
    $user = User::factory()->create();

    expect(route('notifications.index', absolute: false))->toBe('/notifications');

    $this->actingAs($user)
        ->get(route('notifications.index'))
        ->assertOk()
        ->assertSee('No notifications yet');
});

test('unverified users cannot manage notification preferences', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get(route('notifications.edit'))
        ->assertRedirect(route('verification.notice'));
});
