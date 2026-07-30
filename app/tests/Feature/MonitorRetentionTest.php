<?php

use App\Models\Monitor;
use App\Models\MonitorCheck;

test('the retention command deletes only expired monitor checks', function () {
    $monitor = Monitor::factory()->create();
    $expiredCheck = MonitorCheck::factory()->for($monitor)->create([
        'checked_at' => now()->subDays(91),
    ]);
    $retainedCheck = MonitorCheck::factory()->for($monitor)->create([
        'checked_at' => now()->subDays(89),
    ]);

    $this->artisan('monitors:prune-checks', ['--days' => 90])
        ->expectsOutputToContain('Deleted 1 expired monitor checks.')
        ->assertSuccessful();

    $this->assertModelMissing($expiredCheck);
    $this->assertModelExists($retainedCheck);
});

test('the retention command rejects an invalid period', function () {
    $this->artisan('monitors:prune-checks', ['--days' => 0])
        ->expectsOutputToContain('The retention period must be at least one day.')
        ->assertFailed();
});
