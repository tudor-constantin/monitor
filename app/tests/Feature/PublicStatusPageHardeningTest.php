<?php

use App\Models\Monitor;
use App\Models\StatusPage;
use Livewire\Livewire;

test('an out of range history window falls back to the default instead of rendering nothing', function () {
    $statusPage = StatusPage::factory()->create(['is_public' => true]);
    $monitor = Monitor::factory()->create();
    $statusPage->monitors()->attach($monitor, ['position' => 0]);

    Livewire::withQueryParams(['history' => 999])
        ->test('pages::status-pages.public-show', ['statusPage' => $statusPage])
        ->assertSet('historyDays', 30)
        ->assertOk();

    Livewire::withQueryParams(['history' => 90])
        ->test('pages::status-pages.public-show', ['statusPage' => $statusPage])
        ->assertSet('historyDays', 90);
});

test('the public status page route is rate limited', function () {
    $statusPage = StatusPage::factory()->create(['is_public' => true]);

    $middleware = collect(Route::getRoutes()->getByName('status-pages.public')->gatherMiddleware());

    expect($middleware)->toContain('throttle:120,1');

    $this->get(route('status-pages.public', $statusPage))->assertOk();
});
