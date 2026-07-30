<?php

use App\Enums\MonitorStatus;
use App\Models\Monitor;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $this->get(route('dashboard'))
        ->assertRedirect(route('login'));
});

test('an authenticated user sees a product dashboard instead of starter content', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('A clear view of your website monitoring workspace.')
        ->assertSee('Start monitoring your first website')
        ->assertDontSee('Repository')
        ->assertDontSee('Documentation')
        ->assertDontSee('placeholder-pattern');
});

test('the persistent color scheme selector is available from the application sidebar', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Color scheme')
        ->assertSee('Light')
        ->assertSee('Dark')
        ->assertSee('System');
});

test('dashboard statistics and recent monitors are scoped to the authenticated user', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    Monitor::factory()->for($user)->create([
        'name' => 'Operational API',
        'status' => MonitorStatus::Up,
    ]);
    Monitor::factory()->for($user)->create([
        'name' => 'Degraded website',
        'status' => MonitorStatus::Degraded,
    ]);
    Monitor::factory()->for($user)->paused()->create([
        'name' => 'Paused service',
    ]);
    Monitor::factory()->for($otherUser)->create([
        'name' => 'Private monitor from another workspace',
        'status' => MonitorStatus::Down,
    ]);

    Livewire::actingAs($user)
        ->test('pages::dashboard')
        ->assertSet('monitorStats', [
            'total' => 3,
            'operational' => 1,
            'attention' => 1,
            'paused' => 1,
        ])
        ->assertSee('Operational API')
        ->assertSee('Degraded website')
        ->assertSee('Paused service')
        ->assertDontSee('Private monitor from another workspace');
});
