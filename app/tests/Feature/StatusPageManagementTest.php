<?php

use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\User;
use Livewire\Livewire;

test('guests cannot access status page management', function () {
    $statusPage = StatusPage::factory()->create();

    $this->get(route('status-pages.index'))
        ->assertRedirect(route('login'));
    $this->get(route('status-pages.create'))
        ->assertRedirect(route('login'));
    $this->get(route('status-pages.edit', $statusPage))
        ->assertRedirect(route('login'));
});

test('a user only sees their own status pages', function () {
    $user = User::factory()->create();
    $ownStatusPage = StatusPage::factory()->for($user)->create(['name' => 'My public status']);
    $otherStatusPage = StatusPage::factory()->create(['name' => 'Private workspace status']);

    $this->actingAs($user)
        ->get(route('status-pages.index'))
        ->assertOk()
        ->assertSee($ownStatusPage->name)
        ->assertDontSee($otherStatusPage->name);
});

test('a user can search status pages and preview a draft from the list', function () {
    $user = User::factory()->create();
    StatusPage::factory()->count(12)->for($user)->create();
    $matchingStatusPage = StatusPage::factory()->for($user)->create([
        'name' => 'Customer platform',
        'is_public' => false,
    ]);

    Livewire::actingAs($user)
        ->test('pages::status-pages.index')
        ->set('search', 'Customer platform')
        ->assertSee($matchingStatusPage->name)
        ->assertSee('Preview')
        ->assertSee(route('status-pages.public', $matchingStatusPage));
});

test('monitor selection remains searchable when creating a status page', function () {
    $user = User::factory()->create();
    Monitor::factory()->count(15)->for($user)->create();
    $matchingMonitor = Monitor::factory()->for($user)->create([
        'name' => 'Needle service',
    ]);

    Livewire::actingAs($user)
        ->test('pages::status-pages.create')
        ->assertSeeHtml('wire:model.live.self="selectedMonitorIds"')
        ->set('monitorSearch', 'Needle service')
        ->assertSee($matchingMonitor->name)
        ->set('selectedMonitorIds', [$matchingMonitor->id])
        ->set('monitorSearch', '')
        ->assertSet('selectedMonitorIds', [$matchingMonitor->id]);
});

test('a user can create a published status page with ordered monitors', function () {
    $user = User::factory()->create();
    $firstMonitor = Monitor::factory()->for($user)->create(['name' => 'API']);
    $secondMonitor = Monitor::factory()->for($user)->create(['name' => 'Website']);

    Livewire::actingAs($user)
        ->test('pages::status-pages.create')
        ->set('name', 'Customer services')
        ->set('description', 'Live availability for our customer-facing services.')
        ->set('is_public', true)
        ->set('selectedMonitorIds', [$secondMonitor->id, $firstMonitor->id])
        ->call('save')
        ->assertHasNoErrors();

    $statusPage = $user->statusPages()->sole();

    expect($statusPage)
        ->name->toBe('Customer services')
        ->slug->toBe('customer-services')
        ->description->toBe('Live availability for our customer-facing services.')
        ->is_public->toBeTrue()
        ->and($statusPage->monitors()->pluck((new Monitor)->qualifyColumn('id'))->all())
        ->toBe([$secondMonitor->id, $firstMonitor->id]);
});

test('websites assigned to another status page are unavailable for selection', function () {
    $user = User::factory()->create();
    $assignedMonitor = Monitor::factory()->for($user)->create([
        'name' => 'Assigned website',
    ]);
    $availableMonitor = Monitor::factory()->for($user)->create([
        'name' => 'Available website',
    ]);
    $existingStatusPage = StatusPage::factory()->for($user)->create();
    $existingStatusPage->monitors()->attach($assignedMonitor);

    Livewire::actingAs($user)
        ->test('pages::status-pages.create')
        ->assertDontSee($assignedMonitor->name)
        ->assertSee($availableMonitor->name);
});

test('a status page cannot claim a website assigned to another status page', function () {
    $user = User::factory()->create();
    $assignedMonitor = Monitor::factory()->for($user)->create();
    $availableMonitor = Monitor::factory()->for($user)->create();
    $existingStatusPage = StatusPage::factory()->for($user)->create();
    $existingStatusPage->monitors()->attach($assignedMonitor);

    Livewire::actingAs($user)
        ->test('pages::status-pages.create')
        ->set('name', 'Conflicting status page')
        ->set('selectedMonitorIds', [$assignedMonitor->id, $availableMonitor->id])
        ->call('save')
        ->assertHasErrors(['selectedMonitorIds']);

    expect($user->statusPages()->count())->toBe(1);
});

test('a status page cannot include another users monitor', function () {
    $user = User::factory()->create();
    $ownMonitor = Monitor::factory()->for($user)->create();
    $otherMonitor = Monitor::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::status-pages.create')
        ->set('name', 'Unsafe selection')
        ->set('selectedMonitorIds', [$ownMonitor->id, $otherMonitor->id])
        ->call('save')
        ->assertHasErrors(['selectedMonitorIds.1']);

    expect($user->statusPages()->count())->toBe(0);
});

test('a user can update and delete their status page', function () {
    $user = User::factory()->create();
    $firstMonitor = Monitor::factory()->for($user)->create();
    $secondMonitor = Monitor::factory()->for($user)->create();
    $statusPage = StatusPage::factory()->for($user)->create([
        'name' => 'Initial name',
        'slug' => 'stable-public-url',
    ]);
    $statusPage->monitors()->attach($firstMonitor, ['position' => 0]);

    Livewire::actingAs($user)
        ->test('pages::status-pages.edit', ['statusPage' => $statusPage])
        ->set('name', 'Updated name')
        ->set('description', 'Updated description')
        ->set('is_public', true)
        ->set('selectedMonitorIds', [$secondMonitor->id])
        ->call('save')
        ->assertHasNoErrors();

    $statusPage->refresh();

    expect($statusPage)
        ->name->toBe('Updated name')
        ->slug->toBe('stable-public-url')
        ->description->toBe('Updated description')
        ->is_public->toBeTrue()
        ->and($statusPage->monitors()->sole()->is($secondMonitor))->toBeTrue();

    Livewire::actingAs($user)
        ->test('pages::status-pages.edit', ['statusPage' => $statusPage])
        ->call('delete')
        ->assertRedirect(route('status-pages.index'));

    $this->assertModelMissing($statusPage);
});

test('editing a status page shows its websites and only unassigned alternatives', function () {
    $user = User::factory()->create();
    $currentMonitor = Monitor::factory()->for($user)->create([
        'name' => 'Current website',
    ]);
    $assignedElsewhere = Monitor::factory()->for($user)->create([
        'name' => 'Assigned elsewhere',
    ]);
    $availableMonitor = Monitor::factory()->for($user)->create([
        'name' => 'Available alternative',
    ]);
    $statusPage = StatusPage::factory()->for($user)->create();
    $otherStatusPage = StatusPage::factory()->for($user)->create();
    $statusPage->monitors()->attach($currentMonitor);
    $otherStatusPage->monitors()->attach($assignedElsewhere);

    Livewire::actingAs($user)
        ->test('pages::status-pages.edit', ['statusPage' => $statusPage])
        ->assertSee($currentMonitor->name)
        ->assertSee($availableMonitor->name)
        ->assertDontSee($assignedElsewhere->name);
});

test('a draft status page has a direct preview action while editing', function () {
    $statusPage = StatusPage::factory()->create([
        'is_public' => false,
    ]);

    $this->actingAs($statusPage->user)
        ->get(route('status-pages.edit', $statusPage))
        ->assertOk()
        ->assertSee('Preview draft')
        ->assertSee(route('status-pages.public', $statusPage))
        ->assertSee('Delete status page?')
        ->assertDontSee('wire:confirm', false);
});

test('a user cannot edit another users status page', function () {
    $user = User::factory()->create();
    $statusPage = StatusPage::factory()->create();

    $this->actingAs($user)
        ->get(route('status-pages.edit', $statusPage))
        ->assertForbidden();
});
