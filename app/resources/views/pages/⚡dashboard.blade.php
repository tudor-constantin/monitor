<?php

use App\Actions\Monitors\PauseMonitor;
use App\Actions\Monitors\ResumeMonitor;
use App\Enums\MonitorStatus;
use App\Models\Monitor;
use App\Models\User;
use App\Services\Monitoring\MonitorMetricsService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component
{
    protected MonitorMetricsService $monitorMetrics;

    public function boot(MonitorMetricsService $monitorMetrics): void
    {
        $this->monitorMetrics = $monitorMetrics;
    }

    /**
     * @return array{total: int, operational: int, attention: int, paused: int}
     */
    #[Computed]
    public function monitorStats(): array
    {
        $user = $this->user();

        $user->loadCount([
            'monitors',
            'monitors as operational_monitors_count' => fn ($query) => $query
                ->where('status', MonitorStatus::Up),
            'monitors as attention_monitors_count' => fn ($query) => $query
                ->whereIn('status', [MonitorStatus::Degraded, MonitorStatus::Down]),
            'monitors as paused_monitors_count' => fn ($query) => $query
                ->where('status', MonitorStatus::Paused),
        ]);

        return [
            'total' => (int) $user->getAttribute('monitors_count'),
            'operational' => (int) $user->getAttribute('operational_monitors_count'),
            'attention' => (int) $user->getAttribute('attention_monitors_count'),
            'paused' => (int) $user->getAttribute('paused_monitors_count'),
        ];
    }

    /**
     * @return Collection<int, Monitor>
     */
    #[Computed]
    public function recentMonitors(): Collection
    {
        return $this->monitorMetrics->dashboardMonitors($this->user());
    }

    public function pause(int $monitorId, PauseMonitor $pauseMonitor): void
    {
        $monitor = $this->user()->monitors()->findOrFail($monitorId);

        Gate::authorize('pause', $monitor);
        $pauseMonitor->handle($monitor);
        unset($this->recentMonitors, $this->monitorStats);

        Flux::toast(variant: 'success', text: __('Website paused.'));
    }

    public function resume(int $monitorId, ResumeMonitor $resumeMonitor): void
    {
        $monitor = $this->user()->monitors()->findOrFail($monitorId);

        Gate::authorize('resume', $monitor);
        $resumeMonitor->handle($monitor);
        unset($this->recentMonitors, $this->monitorStats);

        Flux::toast(variant: 'success', text: __('Website resumed.'));
    }

    private function user(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
};
?>

<section class="w-full space-y-8" wire:poll.30s>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl">{{ __('Dashboard') }}</flux:heading>
            <flux:subheading>{{ __('A clear view of your website monitoring workspace.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" icon="plus" :href="route('monitors.create')" wire:navigate>
            {{ __('Add website') }}
        </flux:button>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <flux:card class="space-y-4">
            <div class="flex items-center justify-between">
                <flux:text>{{ __('Total websites') }}</flux:text>
                <flux:icon.globe-alt class="size-5 text-zinc-400" />
            </div>
            <div class="text-3xl font-semibold tracking-tight">{{ $this->monitorStats['total'] }}</div>
        </flux:card>

        <flux:card class="space-y-4">
            <div class="flex items-center justify-between">
                <flux:text>{{ __('Operational') }}</flux:text>
                <flux:icon.check-circle class="size-5 text-emerald-500" />
            </div>
            <div class="text-3xl font-semibold tracking-tight">{{ $this->monitorStats['operational'] }}</div>
        </flux:card>

        <flux:card class="space-y-4">
            <div class="flex items-center justify-between">
                <flux:text>{{ __('Needs attention') }}</flux:text>
                <flux:icon.exclamation-triangle class="size-5 text-amber-500" />
            </div>
            <div class="text-3xl font-semibold tracking-tight">{{ $this->monitorStats['attention'] }}</div>
        </flux:card>

        <flux:card class="space-y-4">
            <div class="flex items-center justify-between">
                <flux:text>{{ __('Paused') }}</flux:text>
                <flux:icon.pause class="size-5 text-blue-500" />
            </div>
            <div class="text-3xl font-semibold tracking-tight">{{ $this->monitorStats['paused'] }}</div>
        </flux:card>
    </div>

    <div class="space-y-5">
        <div class="flex flex-col items-start gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
                <flux:heading size="lg">{{ __('Website health') }}</flux:heading>
                <flux:text>{{ __('Current availability and the last 24 hours at a glance.') }}</flux:text>
            </div>

            @if ($this->recentMonitors->isNotEmpty())
                <flux:button variant="ghost" icon-trailing="arrow-right" :href="route('monitors.index')" wire:navigate>
                    {{ __('View all') }}
                </flux:button>
            @endif
        </div>

        @if ($this->recentMonitors->isEmpty())
            <flux:card class="flex flex-col items-center gap-4 border-dashed px-6 py-12 text-center">
                <div class="rounded-full bg-zinc-100 p-3 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                    <flux:icon.signal class="size-7" />
                </div>
                <div>
                    <flux:heading>{{ __('Start monitoring your first website') }}</flux:heading>
                    <flux:text>{{ __('Add a public HTTP or HTTPS endpoint to your workspace.') }}</flux:text>
                </div>
                <flux:button variant="primary" icon="plus" :href="route('monitors.create')" wire:navigate>
                    {{ __('Add website') }}
                </flux:button>
            </flux:card>
        @else
            <div class="grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
                @foreach ($this->recentMonitors as $monitor)
                    <flux:card class="min-w-0 overflow-hidden space-y-5" wire:key="dashboard-monitor-{{ $monitor->id }}">
                        <div class="flex min-w-0 items-start justify-between gap-3">
                            <div class="flex min-w-0 flex-1 items-center gap-3 overflow-hidden">
                                <x-monitors.favicon :monitor="$monitor" />
                                <div class="min-w-0">
                                    <flux:link
                                        class="block truncate font-semibold"
                                        :href="route('monitors.show', $monitor)"
                                        wire:navigate
                                    >
                                        {{ $monitor->name }}
                                    </flux:link>
                                    <flux:text class="block truncate">{{ $monitor->url }}</flux:text>
                                </div>
                            </div>
                            <flux:badge class="shrink-0" :color="$monitor->status->color()" size="sm">
                                {{ $monitor->status->label() }}
                            </flux:badge>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <flux:text>{{ __('Last response') }}</flux:text>
                                <div class="mt-1 text-lg font-semibold">
                                    @if ($monitor->getAttribute('last_response_time_ms') !== null)
                                        {{ number_format((int) $monitor->getAttribute('last_response_time_ms')) }} ms
                                    @else
                                        {{ __('No data') }}
                                    @endif
                                </div>
                            </div>
                            <div>
                                <flux:text>{{ __('Uptime · 24h') }}</flux:text>
                                <div class="mt-1 text-lg font-semibold">
                                    @if ($monitor->getAttribute('uptime_24_hours') !== null)
                                        {{ number_format((float) $monitor->getAttribute('uptime_24_hours'), 2) }}%
                                    @else
                                        {{ __('No data') }}
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                            <div class="min-w-0">
                                @if ((bool) $monitor->getAttribute('has_active_incident'))
                                    <div class="flex items-center gap-1.5 text-sm font-medium text-red-600 dark:text-red-400">
                                        <span class="size-2 rounded-full bg-red-500"></span>
                                        {{ __('Active incident') }}
                                    </div>
                                @else
                                    <flux:text>
                                        {{ $monitor->last_checked_at?->diffForHumans() ?? __('Not checked yet') }}
                                    </flux:text>
                                @endif
                            </div>

                            @if ($monitor->is_active)
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="pause"
                                    wire:click="pause({{ $monitor->id }})"
                                >
                                    {{ __('Pause') }}
                                </flux:button>
                            @else
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="play"
                                    wire:click="resume({{ $monitor->id }})"
                                >
                                    {{ __('Resume') }}
                                </flux:button>
                            @endif
                        </div>
                    </flux:card>
                @endforeach
            </div>
        @endif
    </div>
</section>
