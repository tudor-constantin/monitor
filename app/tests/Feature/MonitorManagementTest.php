<?php

use App\Actions\Monitors\CreateMonitor;
use App\Enums\MonitorStatus;
use App\Models\Monitor;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

test('guests cannot access monitor management', function () {
    $this->get(route('monitors.index'))
        ->assertRedirect(route('login'));
});

test('a user only sees their own monitors', function () {
    $user = User::factory()->create();
    $ownMonitor = Monitor::factory()->for($user)->create(['name' => 'My website']);
    $otherMonitor = Monitor::factory()->create(['name' => 'Another website']);

    $this->actingAs($user)
        ->get(route('monitors.index'))
        ->assertOk()
        ->assertSee($ownMonitor->name)
        ->assertDontSee($otherMonitor->name);
});

test('a user can search and filter a large monitor list', function () {
    $user = User::factory()->create();
    Monitor::factory()->count(20)->for($user)->create([
        'status' => MonitorStatus::Up,
    ]);
    $matchingMonitor = Monitor::factory()->for($user)->create([
        'name' => 'Critical checkout',
        'url' => 'https://checkout.example.com/',
        'status' => MonitorStatus::Down,
    ]);

    Livewire::actingAs($user)
        ->test('pages::monitors.index')
        ->set('search', 'checkout')
        ->assertSee($matchingMonitor->name)
        ->assertDontSee('No matching websites')
        ->set('status', MonitorStatus::Up->value)
        ->assertSee('No matching websites')
        ->call('resetFilters')
        ->assertSet('search', '')
        ->assertSet('status', 'all');
});

test('a user can create a monitor with a normalized URL', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::monitors.create')
        ->set('name', 'Example service')
        ->set('url', ' HTTPS://Example.COM:443/status?full=1#health ')
        ->set('expected_status_code', 204)
        ->set('interval_seconds', 600)
        ->set('timeout_seconds', 15)
        ->call('save')
        ->assertHasNoErrors();

    $monitor = $user->monitors()->sole();

    expect($monitor)
        ->name->toBe('Example service')
        ->url->toBe('https://example.com/status?full=1')
        ->method->toBe('GET')
        ->expected_status_code->toBe(204)
        ->interval_seconds->toBe(600)
        ->timeout_seconds->toBe(15)
        ->status->toBe(MonitorStatus::Pending)
        ->is_active->toBeTrue()
        ->next_check_at->not->toBeNull();
});

test('a user cannot monitor the same normalized URL twice', function () {
    Queue::fake();

    $user = User::factory()->create();
    Monitor::factory()->for($user)->create([
        'url' => 'https://example.com/status',
    ]);

    Livewire::actingAs($user)
        ->test('pages::monitors.create')
        ->set('name', 'Duplicate website')
        ->set('url', ' HTTPS://EXAMPLE.COM:443/status#health ')
        ->call('save')
        ->assertHasErrors(['url' => 'This website is already being monitored.']);

    expect($user->monitors()->count())->toBe(1);
});

test('monitor creation is rate limited per user', function () {
    Queue::fake();
    config()->set('monitoring.monitor_creation_limit_per_minute', 1);

    $user = User::factory()->create();
    $key = "monitor-creation:{$user->id}";
    RateLimiter::clear($key);
    $attributes = [
        'name' => 'First monitor',
        'url' => 'https://example.com/',
        'expected_status_code' => 200,
        'interval_seconds' => 300,
        'timeout_seconds' => 10,
    ];

    app(CreateMonitor::class)->handle($user, $attributes);

    expect(fn () => app(CreateMonitor::class)->handle($user, [
        ...$attributes,
        'name' => 'Second monitor',
    ]))->toThrow(ValidationException::class);

    expect($user->monitors()->count())->toBe(1);
    RateLimiter::clear($key);
});

test('a monitor URL must point to a public HTTP or HTTPS address', function (string $url) {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::monitors.create')
        ->set('name', 'Unsafe monitor')
        ->set('url', $url)
        ->call('save')
        ->assertHasErrors(['url']);

    expect($user->monitors()->count())->toBe(0);
})->with([
    'localhost' => 'http://localhost/admin',
    'private IPv4' => 'http://10.0.0.10/',
    'loopback IPv6' => 'http://[::1]/',
    'credentials' => 'https://admin:secret@example.com/',
    'unsupported port' => 'https://example.com:8443/',
    'unsupported protocol' => 'ftp://example.com/',
]);

test('a user can update their monitor', function () {
    $user = User::factory()->create();
    $monitor = Monitor::factory()->for($user)->create([
        'name' => 'Old name',
        'url' => 'https://old.example.com/',
        'status' => MonitorStatus::Up,
        'last_checked_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test('pages::monitors.edit', ['monitor' => $monitor])
        ->set('name', 'New name')
        ->set('url', 'https://new.example.com/health')
        ->set('interval_seconds', 60)
        ->call('save')
        ->assertHasNoErrors();

    $monitor->refresh();

    expect($monitor)
        ->name->toBe('New name')
        ->url->toBe('https://new.example.com/health')
        ->interval_seconds->toBe(60)
        ->status->toBe(MonitorStatus::Pending)
        ->last_checked_at->toBeNull();
});

test('a user cannot update a website to another monitored URL', function () {
    Queue::fake();

    $user = User::factory()->create();
    $existingMonitor = Monitor::factory()->for($user)->create([
        'url' => 'https://example.com/',
    ]);
    $monitor = Monitor::factory()->for($user)->create([
        'url' => 'https://status.example.com/',
    ]);

    Livewire::actingAs($user)
        ->test('pages::monitors.edit', ['monitor' => $monitor])
        ->set('url', 'HTTPS://EXAMPLE.COM:443')
        ->call('save')
        ->assertHasErrors(['url' => 'This website is already being monitored.']);

    expect($monitor->refresh()->url)->toBe('https://status.example.com/')
        ->and($existingMonitor->refresh()->url)->toBe('https://example.com/');
});

test('a user can pause and resume their monitor', function () {
    $user = User::factory()->create();
    $monitor = Monitor::factory()->for($user)->create(['status' => MonitorStatus::Up]);

    Livewire::actingAs($user)
        ->test('pages::monitors.index')
        ->call('pause', $monitor->id)
        ->assertHasNoErrors();

    expect($monitor->refresh())
        ->status->toBe(MonitorStatus::Paused)
        ->is_active->toBeFalse()
        ->next_check_at->toBeNull();

    Livewire::actingAs($user)
        ->test('pages::monitors.index')
        ->call('resume', $monitor->id)
        ->assertHasNoErrors();

    expect($monitor->refresh())
        ->status->toBe(MonitorStatus::Pending)
        ->is_active->toBeTrue()
        ->next_check_at->not->toBeNull();
});

test('a user can delete their monitor', function () {
    $user = User::factory()->create();
    $monitor = Monitor::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test('pages::monitors.index')
        ->call('delete', $monitor->id)
        ->assertHasNoErrors();

    $this->assertModelMissing($monitor);
});

test('monitor deletion is presented through a confirmation modal', function () {
    $user = User::factory()->create();
    $monitor = Monitor::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test('pages::monitors.index')
        ->call('confirmDelete', $monitor->id)
        ->assertSet('pendingDeletionId', $monitor->id)
        ->assertSee('Delete website?')
        ->assertSee('This action cannot be undone.');

    $this->assertModelExists($monitor);
});

test('a user cannot view or modify another users monitor', function () {
    $user = User::factory()->create();
    $otherMonitor = Monitor::factory()->create();

    $this->actingAs($user)
        ->get(route('monitors.show', $otherMonitor))
        ->assertForbidden();

    $this->actingAs($user)
        ->get(route('monitors.edit', $otherMonitor))
        ->assertForbidden();

    expect(fn () => Livewire::actingAs($user)
        ->test('pages::monitors.index')
        ->call('pause', $otherMonitor->id))
        ->toThrow(ModelNotFoundException::class);

    expect($otherMonitor->refresh()->is_active)->toBeTrue();
});
