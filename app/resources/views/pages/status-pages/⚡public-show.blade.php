<?php

use App\Actions\StatusPages\RequestStatusPageSubscription;
use App\Enums\StatusPageHealth;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Services\StatusPages\StatusPageHealthService;
use App\Services\StatusPages\StatusPageHistoryService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.public'), Title('Service status')] class extends Component
{
    use WithPagination;

    #[Locked]
    public StatusPage $statusPage;

    protected StatusPageHealthService $statusPageHealth;

    protected StatusPageHistoryService $statusPageHistory;

    public string $serviceSearch = '';

    public string $subscriptionEmail = '';

    public bool $subscribeToSpecificServices = false;

    /** @var list<int|string> */
    public array $selectedSubscriptionMonitorIds = [];

    public string $subscriptionSearch = '';

    public bool $subscriptionRequested = false;

    #[Url(as: 'history')]
    public int $historyDays = 30;

    public function boot(
        StatusPageHealthService $statusPageHealth,
        StatusPageHistoryService $statusPageHistory,
    ): void
    {
        $this->statusPageHealth = $statusPageHealth;
        $this->statusPageHistory = $statusPageHistory;
    }

    public function mount(StatusPage $statusPage): void
    {
        $this->statusPage = $statusPage;
        $this->ensureVisible();
    }

    public function hydrate(): void
    {
        $this->statusPage->refresh();

        $this->ensureVisible();
    }

    /**
     */
    #[Computed]
    public function monitors(): LengthAwarePaginator
    {
        $search = trim($this->serviceSearch);

        return $this->statusPage
            ->monitors()
            ->select([
                (new Monitor)->qualifyColumn('id'),
                (new Monitor)->qualifyColumn('name'),
                (new Monitor)->qualifyColumn('favicon_path'),
                (new Monitor)->qualifyColumn('favicon_fetched_at'),
                (new Monitor)->qualifyColumn('status'),
                (new Monitor)->qualifyColumn('last_checked_at'),
            ])
            ->when($search !== '', fn ($query) => $query->whereLike('name', "%{$search}%"))
            ->paginate(
                perPage: 25,
                pageName: 'servicesPage',
            );
    }

    #[Computed]
    public function health(): StatusPageHealth
    {
        return $this->statusPageHealth->determineForStatusPage($this->statusPage);
    }

    /**
     * @return array{
     *     starts_at: string,
     *     ends_at: string,
     *     monitors: array<int, array{
     *         uptime_percentage: float|null,
     *         total_checks: int,
     *         segments: list<array{date: string, label: string, state: string}>
     *     }>
     * }
     */
    #[Computed]
    public function history(): array
    {
        return $this->statusPageHistory->forMonitors(
            $this->monitors->getCollection(),
            $this->historyDays,
        );
    }

    public function setHistoryDays(int $days): void
    {
        $this->historyDays = in_array($days, [30, 90], true) ? $days : 30;
    }

    public function updatedServiceSearch(): void
    {
        $this->resetPage(pageName: 'servicesPage');
    }

    /**
     */
    #[Computed]
    public function subscriptionMonitors(): LengthAwarePaginator
    {
        $search = trim($this->subscriptionSearch);

        return $this->statusPage
            ->monitors()
            ->select([
                (new Monitor)->qualifyColumn('id'),
                (new Monitor)->qualifyColumn('name'),
                (new Monitor)->qualifyColumn('favicon_path'),
                (new Monitor)->qualifyColumn('favicon_fetched_at'),
            ])
            ->when($search !== '', fn ($query) => $query->whereLike('name', "%{$search}%"))
            ->paginate(
                perPage: 8,
                pageName: 'subscriptionsPage',
            );
    }

    public function updatedSubscriptionSearch(): void
    {
        $this->resetPage(pageName: 'subscriptionsPage');
    }

    public function subscribe(RequestStatusPageSubscription $requestStatusPageSubscription): void
    {
        abort_unless($this->statusPage->is_public, 404);

        $validated = $this->validate([
            'subscriptionEmail' => ['required', 'string', 'email:rfc', 'max:254'],
            'subscribeToSpecificServices' => ['required', 'boolean'],
            'selectedSubscriptionMonitorIds' => [
                Rule::requiredIf($this->subscribeToSpecificServices),
                'array',
            ],
            'selectedSubscriptionMonitorIds.*' => [
                'integer',
                'distinct',
                Rule::exists('status_page_monitor', 'monitor_id')
                    ->where('status_page_id', $this->statusPage->id),
            ],
        ]);

        $requestStatusPageSubscription->handle(
            $this->statusPage,
            $validated['subscriptionEmail'],
            ! $validated['subscribeToSpecificServices'],
            array_map(intval(...), $validated['selectedSubscriptionMonitorIds']),
            request()->ip() ?? 'unknown',
        );

        $this->subscriptionRequested = true;
    }

    public function resetSubscriptionForm(): void
    {
        $this->reset(
            'subscriptionEmail',
            'subscribeToSpecificServices',
            'selectedSubscriptionMonitorIds',
            'subscriptionSearch',
            'subscriptionRequested',
        );
        $this->resetValidation();
        $this->resetPage(pageName: 'subscriptionsPage');
    }

    /**
     * @return Collection<int, Incident>
     */
    #[Computed]
    public function recentIncidents(): Collection
    {
        return Incident::query()
            ->select([
                'id',
                'monitor_id',
                'started_at',
                'resolved_at',
                'duration_seconds',
            ])
            ->whereHas(
                'monitor.statusPages',
                fn ($query) => $query->whereKey($this->statusPage->getKey()),
            )
            ->with('monitor:id,name')
            ->latest('started_at')
            ->limit(10)
            ->get();
    }

    private function ensureVisible(): void
    {
        if ($this->statusPage->is_public) {
            return;
        }

        $user = Auth::user();

        abort_unless($user !== null && Gate::forUser($user)->allows('view', $this->statusPage), 404);
    }
};
?>

<main class="mx-auto w-full max-w-5xl space-y-8 px-6 py-10 sm:py-14" wire:poll.60s>
    @if (session('subscription_status'))
        <flux:callout icon="check-circle" color="green">
            <flux:callout.text>{{ session('subscription_status') }}</flux:callout.text>
        </flux:callout>
    @endif

    @if (! $statusPage->is_public)
        <flux:callout icon="eye" color="amber">
            <flux:callout.heading>{{ __('Draft preview') }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('Only you can see this preview. Publish the status page when it is ready for visitors.') }}
            </flux:callout.text>
        </flux:callout>
    @endif

    <section class="space-y-4 text-center">
        <flux:heading size="xl">{{ $statusPage->name }}</flux:heading>
        @if ($statusPage->description !== null)
            <p class="mx-auto max-w-2xl text-zinc-600 dark:text-zinc-300">{{ $statusPage->description }}</p>
        @endif
    </section>

    <section class="space-y-4">
        <flux:card class="overflow-hidden p-0">
            <div class="space-y-5 border-b border-zinc-200 p-5 dark:border-zinc-700 sm:p-6">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div class="flex items-start gap-3">
                        <div @class([
                            'mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full',
                            'bg-emerald-100 text-emerald-600 dark:bg-emerald-950 dark:text-emerald-400' => $this->health === StatusPageHealth::Operational,
                            'bg-red-100 text-red-600 dark:bg-red-950 dark:text-red-400' => $this->health === StatusPageHealth::Outage,
                            'bg-amber-100 text-amber-600 dark:bg-amber-950 dark:text-amber-400' => $this->health === StatusPageHealth::Degraded,
                            'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400' => $this->health === StatusPageHealth::Monitoring,
                        ])>
                            @if ($this->health === StatusPageHealth::Operational)
                                <flux:icon.check class="size-5" />
                            @elseif ($this->health === StatusPageHealth::Outage)
                                <flux:icon.x-mark class="size-5" />
                            @elseif ($this->health === StatusPageHealth::Degraded)
                                <flux:icon.exclamation-triangle class="size-5" />
                            @else
                                <flux:icon.clock class="size-5" />
                            @endif
                        </div>
                        <div>
                            <flux:heading size="lg">{{ __('System status') }}</flux:heading>
                            <flux:text class="mt-1">
                                {{ $this->health->label() }} ·
                                {{ $this->history['starts_at'] }} – {{ $this->history['ends_at'] }}
                            </flux:text>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <flux:button
                            size="sm"
                            :variant="$historyDays === 30 ? 'primary' : 'ghost'"
                            wire:click="setHistoryDays(30)"
                        >
                            {{ __('30 days') }}
                        </flux:button>
                        <flux:button
                            size="sm"
                            :variant="$historyDays === 90 ? 'primary' : 'ghost'"
                            wire:click="setHistoryDays(90)"
                        >
                            {{ __('90 days') }}
                        </flux:button>
                    </div>
                </div>

                <flux:input
                    wire:model.live.debounce.300ms="serviceSearch"
                    icon="magnifying-glass"
                    :placeholder="__('Search services')"
                    clearable
                />
            </div>

            @if ($this->monitors->isEmpty())
                <div class="px-6 py-12 text-center">
                    <flux:heading>
                        {{ $serviceSearch === '' ? __('No services published') : __('No matching services') }}
                    </flux:heading>
                    <flux:text>
                        {{ $serviceSearch === ''
                            ? __('This status page does not include any services yet.')
                            : __('Try a different search term.') }}
                    </flux:text>
                </div>
            @else
                <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($this->monitors as $monitor)
                    @php($monitorHistory = $this->history['monitors'][$monitor->id])
                    <div
                        class="space-y-4 px-5 py-5 sm:px-6"
                        wire:key="public-status-monitor-{{ $monitor->id }}"
                    >
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex min-w-0 items-center gap-3">
                                <x-monitors.favicon :monitor="$monitor" />
                                <div class="min-w-0">
                                    <div class="truncate font-medium">
                                        {{ $monitor->pivot->display_name ?? $monitor->name }}
                                    </div>
                                    <flux:text>
                                        {{ $monitor->last_checked_at?->diffForHumans() ?? __('Awaiting first check') }}
                                    </flux:text>
                                </div>
                            </div>
                            <div class="flex items-center justify-between gap-4 sm:justify-end">
                                <span class="text-sm text-zinc-500 dark:text-zinc-400">
                                    {{ $monitorHistory['uptime_percentage'] === null
                                        ? __('No uptime data')
                                        : number_format($monitorHistory['uptime_percentage'], 2).'% '.__('uptime') }}
                                </span>
                                <flux:badge :color="$monitor->status->color()">
                                    {{ $monitor->status->label() }}
                                </flux:badge>
                            </div>
                        </div>

                        <div class="overflow-x-auto pb-1">
                            <div @class([
                                'flex h-6 gap-1',
                                'min-w-[34rem]' => $historyDays === 30,
                                'min-w-[54rem]' => $historyDays === 90,
                            ])>
                                @foreach ($monitorHistory['segments'] as $segment)
                                    <span
                                        @class([
                                            'h-full min-w-1 flex-1 rounded-sm',
                                            'bg-emerald-500' => $segment['state'] === 'operational',
                                            'bg-amber-400' => $segment['state'] === 'degraded',
                                            'bg-red-500' => $segment['state'] === 'outage',
                                            'bg-zinc-200 dark:bg-zinc-700' => $segment['state'] === 'no-data',
                                        ])
                                        title="{{ $segment['label'] }}"
                                        aria-label="{{ $segment['label'] }}"
                                    ></span>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endforeach
                </div>
            @endif

            @if ($this->monitors->hasPages())
                <div class="border-t border-zinc-200 p-5 dark:border-zinc-700">
                    {{ $this->monitors->links(data: ['scrollTo' => false]) }}
                </div>
            @endif
        </flux:card>

        <div class="flex flex-wrap items-center gap-x-5 gap-y-2 px-1 text-xs text-zinc-500 dark:text-zinc-400">
            <span class="flex items-center gap-2"><span class="size-2.5 rounded-sm bg-emerald-500"></span>{{ __('Operational') }}</span>
            <span class="flex items-center gap-2"><span class="size-2.5 rounded-sm bg-amber-400"></span>{{ __('Degraded') }}</span>
            <span class="flex items-center gap-2"><span class="size-2.5 rounded-sm bg-red-500"></span>{{ __('Outage') }}</span>
            <span class="flex items-center gap-2"><span class="size-2.5 rounded-sm bg-zinc-200 dark:bg-zinc-700"></span>{{ __('No data') }}</span>
        </div>
    </section>

    <section class="space-y-4">
        <div>
            <flux:heading size="lg">{{ __('Recent incidents') }}</flux:heading>
            <flux:text>{{ __('Confirmed service disruptions and recoveries.') }}</flux:text>
        </div>

        @if ($this->recentIncidents->isEmpty())
            <flux:card class="flex items-center gap-4">
                <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-950 dark:text-emerald-400">
                    <flux:icon.check class="size-5" />
                </div>
                <div>
                    <flux:heading>{{ __('No recent incidents') }}</flux:heading>
                    <flux:text>{{ __('No confirmed disruptions have been recorded for these services.') }}</flux:text>
                </div>
            </flux:card>
        @else
            <div class="space-y-3">
                @foreach ($this->recentIncidents as $incident)
                    <flux:card class="flex items-start gap-4" wire:key="public-incident-{{ $incident->id }}">
                        <div class="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full {{ $incident->resolved_at === null ? 'bg-red-100 text-red-600 dark:bg-red-950 dark:text-red-400' : 'bg-emerald-100 text-emerald-600 dark:bg-emerald-950 dark:text-emerald-400' }}">
                            @if ($incident->resolved_at === null)
                                <flux:icon.exclamation-triangle class="size-4" />
                            @else
                                <flux:icon.check class="size-4" />
                            @endif
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="font-medium">
                                    {{ $incident->monitor->name }} ·
                                    {{ $incident->resolved_at === null ? __('Investigating an outage') : __('Service recovered') }}
                                </div>
                                <flux:badge :color="$incident->resolved_at === null ? 'red' : 'green'" size="sm">
                                    {{ $incident->resolved_at === null ? __('Ongoing') : __('Resolved') }}
                                </flux:badge>
                            </div>
                            <flux:text class="mt-1">
                                {{ __('Started :time', ['time' => $incident->started_at->diffForHumans()]) }}
                            </flux:text>
                        </div>
                    </flux:card>
                @endforeach
            </div>
        @endif
    </section>

    <footer class="border-t border-zinc-200 pt-6 text-center dark:border-zinc-800">
        <flux:text>
            {{ __('Automatically updated every minute by Monitor.') }}
        </flux:text>
    </footer>

    @if ($statusPage->is_public)
        <flux:modal
            name="subscribe-status-updates"
            wire:close="resetSubscriptionForm"
            class="w-full max-w-2xl"
            scroll="body"
        >
            @if ($subscriptionRequested)
                <div class="space-y-6 text-center">
                    <div class="mx-auto flex size-12 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-950 dark:text-emerald-400">
                        <flux:icon.envelope class="size-6" />
                    </div>
                    <div>
                        <flux:heading size="lg">{{ __('Check your email') }}</flux:heading>
                        <flux:text class="mt-2">
                            {{ __('If this address can receive mail, a confirmation link has been sent. The link expires in 60 minutes.') }}
                        </flux:text>
                    </div>
                    <flux:modal.close>
                        <flux:button variant="primary">{{ __('Done') }}</flux:button>
                    </flux:modal.close>
                </div>
            @else
                <form wire:submit="subscribe" class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('Subscribe to updates') }}</flux:heading>
                        <flux:text class="mt-2">
                            {{ __('Receive an email when a selected service goes down or recovers.') }}
                        </flux:text>
                    </div>

                    <flux:field>
                        <flux:label>{{ __('Email address') }}</flux:label>
                        <flux:input
                            type="email"
                            wire:model="subscriptionEmail"
                            autocomplete="email"
                            placeholder="you@example.com"
                            required
                        />
                        <flux:error name="subscriptionEmail" />
                    </flux:field>

                    <flux:checkbox
                        wire:model.live="subscribeToSpecificServices"
                        :label="__('Subscribe to specific services')"
                        :description="__('Leave this unchecked to receive updates for every service on this status page.')"
                    />

                    @if ($subscribeToSpecificServices)
                        <div class="space-y-4">
                            <flux:input
                                wire:model.live.debounce.300ms="subscriptionSearch"
                                icon="magnifying-glass"
                                :placeholder="__('Search services')"
                                clearable
                            />

                            @if ($this->subscriptionMonitors->isEmpty())
                                <div class="rounded-xl border border-dashed border-zinc-300 px-6 py-8 text-center dark:border-zinc-700">
                                    <flux:heading>{{ __('No matching services') }}</flux:heading>
                                    <flux:text>{{ __('Try a different search term.') }}</flux:text>
                                </div>
                            @else
                                <flux:checkbox.group
                                    wire:model="selectedSubscriptionMonitorIds"
                                    class="grid gap-3 sm:grid-cols-2"
                                >
                                    @foreach ($this->subscriptionMonitors as $monitor)
                                        <div
                                            class="flex items-center gap-3 rounded-xl border border-zinc-200 p-3 dark:border-zinc-700"
                                            wire:key="subscription-monitor-{{ $monitor->id }}"
                                        >
                                            <x-monitors.favicon :monitor="$monitor" class="size-9 rounded-lg" />
                                            <div class="min-w-0 flex-1">
                                                <flux:checkbox
                                                    :value="$monitor->id"
                                                    :label="$monitor->pivot->display_name ?? $monitor->name"
                                                />
                                            </div>
                                        </div>
                                    @endforeach
                                </flux:checkbox.group>

                                @if ($this->subscriptionMonitors->hasPages())
                                    {{ $this->subscriptionMonitors->links(data: ['scrollTo' => false]) }}
                                @endif
                            @endif

                            <flux:error name="selectedSubscriptionMonitorIds" />
                            <flux:error name="selectedSubscriptionMonitorIds.*" />
                        </div>
                    @endif

                    <flux:callout icon="shield-check">
                        <flux:callout.text>
                            {{ __('We will send a confirmation link before enabling notifications. Every alert includes an unsubscribe link.') }}
                        </flux:callout.text>
                    </flux:callout>

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button
                            type="submit"
                            variant="primary"
                            wire:loading.attr="disabled"
                            wire:target="subscribe"
                        >
                            {{ __('Send confirmation') }}
                        </flux:button>
                    </div>
                </form>
            @endif
        </flux:modal>
    @endif
</main>
