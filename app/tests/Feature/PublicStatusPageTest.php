<?php

use App\Enums\MonitorStatus;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\User;

test('a published status page is publicly accessible with only selected monitors', function () {
    $user = User::factory()->create();
    $selectedMonitor = Monitor::factory()->for($user)->create([
        'name' => 'Public API',
        'url' => 'https://api.internal.example.com/health',
        'status' => MonitorStatus::Up,
        'last_checked_at' => now()->subMinute(),
    ]);
    $privateMonitor = Monitor::factory()->for($user)->create([
        'name' => 'Internal administration',
        'status' => MonitorStatus::Down,
    ]);
    $statusPage = StatusPage::factory()->published()->for($user)->create([
        'name' => 'Acme service status',
        'description' => 'Live availability information.',
    ]);
    $statusPage->monitors()->attach($selectedMonitor, ['position' => 0]);

    $this->get(route('status-pages.public', $statusPage))
        ->assertOk()
        ->assertSee('Acme service status')
        ->assertSee('All systems operational')
        ->assertSee('Public API')
        ->assertSee('Up')
        ->assertSee('Uptime is the percentage of recorded checks that completed successfully')
        ->assertDontSee($privateMonitor->name)
        ->assertDontSee($selectedMonitor->url);
});

test('an unpublished status page returns not found', function () {
    $statusPage = StatusPage::factory()->create();

    $this->get(route('status-pages.public', $statusPage))
        ->assertNotFound();
});

test('an owner can preview an unpublished status page', function () {
    $statusPage = StatusPage::factory()->create();

    $this->actingAs($statusPage->user)
        ->get(route('status-pages.public', $statusPage))
        ->assertOk()
        ->assertSee('Draft preview')
        ->assertSee($statusPage->name);
});

test('another user cannot preview an unpublished status page', function () {
    $statusPage = StatusPage::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('status-pages.public', $statusPage))
        ->assertNotFound();
});

test('a public status page shows incidents only for selected monitors', function () {
    $user = User::factory()->create();
    $selectedMonitor = Monitor::factory()->for($user)->create(['name' => 'Checkout']);
    $privateMonitor = Monitor::factory()->for($user)->create(['name' => 'Back office']);
    $statusPage = StatusPage::factory()->published()->for($user)->create();
    $statusPage->monitors()->attach($selectedMonitor, ['position' => 0]);

    Incident::factory()->resolved()->for($selectedMonitor)->create([
        'started_at' => now()->subHours(2),
        'resolved_at' => now()->subHour(),
        'duration_seconds' => 3600,
    ]);
    Incident::factory()->resolved()->for($privateMonitor)->create([
        'started_at' => now()->subHours(3),
        'resolved_at' => now()->subHours(2),
        'duration_seconds' => 3600,
    ]);

    $this->get(route('status-pages.public', $statusPage))
        ->assertOk()
        ->assertSee('Checkout')
        ->assertSee('Service recovered')
        ->assertDontSee('Back office');
});

test('a public status page reports service disruptions', function () {
    $user = User::factory()->create();
    $monitor = Monitor::factory()->for($user)->create([
        'name' => 'Website',
        'status' => MonitorStatus::Down,
    ]);
    $statusPage = StatusPage::factory()->published()->for($user)->create();
    $statusPage->monitors()->attach($monitor, ['position' => 0]);

    $this->get(route('status-pages.public', $statusPage))
        ->assertOk()
        ->assertSee('Service disruption detected')
        ->assertSee('Down');
});
