<?php

use App\Actions\Users\PruneReadNotifications;
use App\Models\Monitor;
use App\Models\User;
use Illuminate\Support\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

test('read notifications are pruned once they age out, unread ones are kept', function () {
    Carbon::setTestNow('2026-07-30 12:00:00');

    $user = User::factory()->create();
    storeDownNotification($user);
    storeDownNotification($user, Monitor::factory()->for($user)->create(['name' => 'Second website']));

    $read = $user->notifications()->first();
    $read->forceFill(['read_at' => now()->subDays(60)])->save();

    $deleted = app(PruneReadNotifications::class)->handle(now()->subDays(30));

    expect($deleted)->toBe(1)
        ->and($user->notifications()->count())->toBe(1)
        ->and($user->unreadNotifications()->count())->toBe(1);
});

test('the prune command refuses a nonsensical retention period', function () {
    $this->artisan('notifications:prune --days=0')->assertFailed();
});
