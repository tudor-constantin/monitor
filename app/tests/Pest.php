<?php

use App\Models\Incident;
use App\Models\Monitor;
use App\Models\User;
use App\Notifications\MonitorDownNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Store a delivered "monitor down" in-app notification for $user.
 *
 * The notification is ShouldQueue, so notify() would only enqueue it and no row
 * would ever reach the notifications table; this sends it inline. Shared here
 * because both the inbox and the pruning suites need a stored notification.
 */
function storeDownNotification(User $user, ?Monitor $monitor = null): void
{
    $monitor ??= Monitor::factory()->for($user)->create(['name' => 'Acme website']);
    $incident = Incident::factory()->for($monitor)->create();

    $user->notifyNow(new MonitorDownNotification($incident));
}
