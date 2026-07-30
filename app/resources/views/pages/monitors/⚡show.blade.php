<?php

use App\Actions\Monitors\DeleteMonitor;
use App\Actions\Monitors\PauseMonitor;
use App\Actions\Monitors\ResumeMonitor;
use App\Data\MonitorMetrics;
use App\Data\ResponseTimeSeries;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Services\Monitoring\MonitorMetricsService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Website details')] class extends Component
{
    #[Locked]
    public Monitor $monitor;

    protected MonitorMetricsService $monitorMetrics;

    public function boot(MonitorMetricsService $monitorMetrics): void
    {
        $this->monitorMetrics = $monitorMetrics;
    }

    public function mount(Monitor $monitor): void
    {
        Gate::authorize('view', $monitor);

        $this->monitor = $monitor;
    }

    public function pause(PauseMonitor $pauseMonitor): void
    {
        Gate::authorize('pause', $this->monitor);
        $this->monitor = $pauseMonitor->handle($this->monitor);

        Flux::toast(variant: 'success', text: __('Website paused.'));
    }

    public function resume(ResumeMonitor $resumeMonitor): void
    {
        Gate::authorize('resume', $this->monitor);
        $this->monitor = $resumeMonitor->handle($this->monitor);

        Flux::toast(variant: 'success', text: __('Website resumed.'));
    }

    public function delete(DeleteMonitor $deleteMonitor): void
    {
        Gate::authorize('delete', $this->monitor);
        $deleteMonitor->handle($this->monitor);

        $this->redirectRoute('monitors.index', navigate: true);
    }

    /**
     * @return Collection<int, MonitorCheck>
     */
    #[Computed]
    public function recentChecks(): Collection
    {
        return $this->monitor
            ->checks()
            ->latest('checked_at')
            ->limit(15)
            ->get();
    }

    /**
     * @return Collection<int, Incident>
     */
    #[Computed]
    public function recentIncidents(): Collection
    {
        return $this->monitor
            ->incidents()
            ->latest('started_at')
            ->limit(10)
            ->get();
    }

    #[Computed]
    public function metrics24Hours(): MonitorMetrics
    {
        return $this->monitorMetrics->forPeriod($this->monitor, now()->subDay());
    }

    #[Computed]
    public function metrics7Days(): MonitorMetrics
    {
        return $this->monitorMetrics->forPeriod($this->monitor, now()->subDays(7));
    }

    #[Computed]
    public function metrics30Days(): MonitorMetrics
    {
        return $this->monitorMetrics->forPeriod($this->monitor, now()->subDays(30));
    }

    #[Computed]
    public function responseTimeSeries(): ResponseTimeSeries
    {
        return $this->monitorMetrics->responseTimeSeries($this->monitor, now()->subDay());
    }
};
?>

<section class="w-full space-y-6" wire:poll.30s>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0 space-y-2">
            <div class="flex min-w-0 flex-wrap items-center gap-3">
                <x-monitors.favicon :monitor="$monitor" class="size-12 rounded-2xl" />
                <flux:heading class="min-w-0 break-words" size="xl">{{ $monitor->name }}</flux:heading>
                <flux:badge :color="$monitor->status->color()">{{ $monitor->status->label() }}</flux:badge>
            </div>
            <flux:link class="break-all" :href="$monitor->url" target="_blank" rel="noopener noreferrer">
                {{ $monitor->url }}
            </flux:link>
        </div>

        <div class="flex flex-wrap gap-2">
            @if ($monitor->is_active)
                <flux:button icon="pause" wire:click="pause">{{ __('Pause') }}</flux:button>
            @else
                <flux:button icon="play" wire:click="resume">{{ __('Resume') }}</flux:button>
            @endif
            <flux:button icon="pencil-square" :href="route('monitors.edit', $monitor)" wire:navigate>
                {{ __('Edit') }}
            </flux:button>
            <flux:modal.trigger name="delete-monitor">
                <flux:button variant="danger" icon="trash">
                    {{ __('Delete') }}
                </flux:button>
            </flux:modal.trigger>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <flux:card>
            <flux:text>{{ __('Current status') }}</flux:text>
            <div class="mt-2">
                <flux:badge :color="$monitor->status->color()">{{ $monitor->status->label() }}</flux:badge>
            </div>
        </flux:card>
        <flux:card>
            <flux:text>{{ __('Last checked') }}</flux:text>
            <flux:heading class="mt-1">
                {{ $monitor->last_checked_at?->diffForHumans() ?? __('Not checked yet') }}
            </flux:heading>
        </flux:card>
        <flux:card>
            <flux:text>{{ __('Last response') }}</flux:text>
            <flux:heading class="mt-1">
                {{ $this->responseTimeSeries->latestResponseTimeMs !== null ? number_format($this->responseTimeSeries->latestResponseTimeMs).' ms' : __('No data') }}
            </flux:heading>
        </flux:card>
        <flux:card>
            <flux:text>{{ __('Next check') }}</flux:text>
            <flux:heading class="mt-1">
                {{ $monitor->next_check_at?->diffForHumans() ?? __('Paused') }}
            </flux:heading>
        </flux:card>
    </div>

    <div class="grid gap-4 md:grid-cols-3">
        <flux:card class="space-y-2">
            <div class="flex items-center justify-between">
                <flux:text>{{ __('Uptime · 24 hours') }}</flux:text>
                <flux:icon.clock class="size-5 text-zinc-400" />
            </div>
            <div class="text-2xl font-semibold tracking-tight">{{ $this->metrics24Hours->uptimeLabel() }}</div>
            <flux:text>
                {{ trans_choice(':count check|:count checks', $this->metrics24Hours->totalChecks, ['count' => number_format($this->metrics24Hours->totalChecks)]) }}
            </flux:text>
        </flux:card>
        <flux:card class="space-y-2">
            <div class="flex items-center justify-between">
                <flux:text>{{ __('Uptime · 7 days') }}</flux:text>
                <flux:icon.calendar-days class="size-5 text-zinc-400" />
            </div>
            <div class="text-2xl font-semibold tracking-tight">{{ $this->metrics7Days->uptimeLabel() }}</div>
            <flux:text>
                {{ trans_choice(':count check|:count checks', $this->metrics7Days->totalChecks, ['count' => number_format($this->metrics7Days->totalChecks)]) }}
            </flux:text>
        </flux:card>
        <flux:card class="space-y-2">
            <div class="flex items-center justify-between">
                <flux:text>{{ __('Uptime · 30 days') }}</flux:text>
                <flux:icon.calendar class="size-5 text-zinc-400" />
            </div>
            <div class="text-2xl font-semibold tracking-tight">{{ $this->metrics30Days->uptimeLabel() }}</div>
            <flux:text>
                {{ trans_choice(':count check|:count checks', $this->metrics30Days->totalChecks, ['count' => number_format($this->metrics30Days->totalChecks)]) }}
            </flux:text>
        </flux:card>
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
        <flux:card class="space-y-5 xl:col-span-2">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <flux:heading>{{ __('Response time · 24 hours') }}</flux:heading>
                    <flux:text>{{ __('Up to 48 of the most recent response measurements.') }}</flux:text>
                </div>
                @if ($this->responseTimeSeries->averageResponseTimeMs !== null)
                    <div class="text-left sm:text-right">
                        <flux:text>{{ __('Average') }}</flux:text>
                        <div class="font-semibold">{{ number_format($this->responseTimeSeries->averageResponseTimeMs) }} ms</div>
                    </div>
                @endif
            </div>

            @if ($this->responseTimeSeries->sampleCount === 0)
                <div class="flex min-h-52 items-center justify-center rounded-xl border border-dashed border-zinc-300 px-6 text-center dark:border-zinc-700">
                    <div>
                        <flux:heading>{{ __('No response-time data yet') }}</flux:heading>
                        <flux:text>{{ __('Measurements will appear after successful connections.') }}</flux:text>
                    </div>
                </div>
            @else
                <div class="rounded-xl bg-zinc-50 p-4 dark:bg-zinc-800/50">
                    <svg
                        class="h-52 w-full overflow-visible"
                        viewBox="0 0 100 100"
                        preserveAspectRatio="none"
                        role="img"
                        aria-label="{{ __('Response-time history for the last 24 hours') }}"
                    >
                        <line x1="0" y1="10" x2="100" y2="10" class="stroke-zinc-200 dark:stroke-zinc-700" stroke-width="0.4" />
                        <line x1="0" y1="52.5" x2="100" y2="52.5" class="stroke-zinc-200 dark:stroke-zinc-700" stroke-width="0.4" />
                        <line x1="0" y1="95" x2="100" y2="95" class="stroke-zinc-200 dark:stroke-zinc-700" stroke-width="0.4" />
                        <polyline
                            points="{{ $this->responseTimeSeries->points }}"
                            fill="none"
                            class="stroke-emerald-500"
                            stroke-width="1.5"
                            vector-effect="non-scaling-stroke"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>
                </div>
                <div class="grid grid-cols-3 gap-4">
                    <div>
                        <flux:text>{{ __('Minimum') }}</flux:text>
                        <div class="font-medium">{{ number_format($this->responseTimeSeries->minimumResponseTimeMs) }} ms</div>
                    </div>
                    <div class="text-center">
                        <flux:text>{{ __('Latest') }}</flux:text>
                        <div class="font-medium">{{ number_format($this->responseTimeSeries->latestResponseTimeMs) }} ms</div>
                    </div>
                    <div class="text-right">
                        <flux:text>{{ __('Maximum') }}</flux:text>
                        <div class="font-medium">{{ number_format($this->responseTimeSeries->maximumResponseTimeMs) }} ms</div>
                    </div>
                </div>
            @endif
        </flux:card>

        <flux:card class="space-y-5">
            <div>
                <flux:heading>{{ __('30-day summary') }}</flux:heading>
                <flux:text>{{ __('Availability and incident totals.') }}</flux:text>
            </div>
            <dl class="divide-y divide-zinc-200 dark:divide-zinc-700">
                <div class="flex items-center justify-between gap-4 py-3 first:pt-0">
                    <dt class="text-sm text-zinc-500">{{ __('Successful checks') }}</dt>
                    <dd class="font-medium">{{ number_format($this->metrics30Days->successfulChecks) }}</dd>
                </div>
                <div class="flex items-center justify-between gap-4 py-3">
                    <dt class="text-sm text-zinc-500">{{ __('Failed checks') }}</dt>
                    <dd class="font-medium">{{ number_format($this->metrics30Days->failedChecks) }}</dd>
                </div>
                <div class="flex items-center justify-between gap-4 py-3">
                    <dt class="text-sm text-zinc-500">{{ __('Average response') }}</dt>
                    <dd class="font-medium">{{ $this->metrics30Days->averageResponseTimeLabel() }}</dd>
                </div>
                <div class="flex items-center justify-between gap-4 py-3">
                    <dt class="text-sm text-zinc-500">{{ __('Response range') }}</dt>
                    <dd class="font-medium">
                        {{ $this->metrics30Days->minimumResponseTimeLabel() }} – {{ $this->metrics30Days->maximumResponseTimeLabel() }}
                    </dd>
                </div>
                <div class="flex items-center justify-between gap-4 py-3">
                    <dt class="text-sm text-zinc-500">{{ __('Incidents') }}</dt>
                    <dd class="font-medium">{{ number_format($this->metrics30Days->incidentCount) }}</dd>
                </div>
                <div class="flex items-center justify-between gap-4 py-3 last:pb-0">
                    <dt class="text-sm text-zinc-500">{{ __('Total downtime') }}</dt>
                    <dd class="font-medium">{{ $this->metrics30Days->downtimeLabel() }}</dd>
                </div>
            </dl>
        </flux:card>
    </div>

    <flux:card class="space-y-5">
        <div>
            <flux:heading>{{ __('Recent checks') }}</flux:heading>
            <flux:text>{{ __('The latest availability and response-time results for this endpoint.') }}</flux:text>
        </div>

        @if ($this->recentChecks->isEmpty())
            <div class="rounded-xl border border-dashed border-zinc-300 px-6 py-8 text-center dark:border-zinc-700">
                <flux:heading>{{ __('No checks recorded yet') }}</flux:heading>
                <flux:text>{{ __('The first result will appear after this website is checked.') }}</flux:text>
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Result') }}</flux:table.column>
                    <flux:table.column>{{ __('HTTP status') }}</flux:table.column>
                    <flux:table.column>{{ __('Response time') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Checked') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->recentChecks as $check)
                        <flux:table.row :key="$check->id">
                            <flux:table.cell>
                                <flux:badge :color="$check->status->color()" size="sm">
                                    {{ $check->status->label() }}
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>{{ $check->status_code ?? '—' }}</flux:table.cell>
                            <flux:table.cell>
                                {{ $check->response_time_ms !== null ? $check->response_time_ms.' ms' : '—' }}
                            </flux:table.cell>
                            <flux:table.cell align="end">{{ $check->checked_at->diffForHumans() }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </flux:card>

    <div class="grid gap-6 xl:grid-cols-2">
        <flux:card class="space-y-5">
            <div>
                <flux:heading>{{ __('Incident timeline') }}</flux:heading>
                <flux:text>{{ __('The ten most recent confirmed outages and recoveries.') }}</flux:text>
            </div>

            @if ($this->recentIncidents->isEmpty())
                <div class="rounded-xl border border-dashed border-zinc-300 px-6 py-8 text-center dark:border-zinc-700">
                    <flux:heading>{{ __('No incidents recorded') }}</flux:heading>
                    <flux:text>{{ __('Confirmed outages will appear here.') }}</flux:text>
                </div>
            @else
                <div class="space-y-5">
                    @foreach ($this->recentIncidents as $incident)
                        <div class="relative flex gap-4" wire:key="incident-{{ $incident->id }}">
                            <div class="mt-1 flex size-8 shrink-0 items-center justify-center rounded-full {{ $incident->resolved_at === null ? 'bg-red-100 text-red-600 dark:bg-red-950 dark:text-red-400' : 'bg-emerald-100 text-emerald-600 dark:bg-emerald-950 dark:text-emerald-400' }}">
                                @if ($incident->resolved_at === null)
                                    <flux:icon.exclamation-triangle class="size-4" />
                                @else
                                    <flux:icon.check class="size-4" />
                                @endif
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="font-medium">
                                        {{ $incident->resolved_at === null ? __('Outage in progress') : __('Service recovered') }}
                                    </div>
                                    <flux:badge :color="$incident->resolved_at === null ? 'red' : 'green'" size="sm">
                                        {{ $incident->resolved_at === null ? __('Open') : __('Resolved') }}
                                    </flux:badge>
                                </div>
                                <flux:text class="mt-1">
                                    {{ __('Started :time', ['time' => $incident->started_at->diffForHumans()]) }}
                                    @if ($incident->duration_seconds !== null)
                                        · {{ __('Duration: :seconds seconds', ['seconds' => number_format($incident->duration_seconds)]) }}
                                    @endif
                                </flux:text>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </flux:card>

        <flux:card class="space-y-5">
            <div>
                <flux:heading>{{ __('Website configuration') }}</flux:heading>
                <flux:text>{{ __('The request and scheduling settings currently in use.') }}</flux:text>
            </div>
            <dl class="divide-y divide-zinc-200 dark:divide-zinc-700">
                <div class="flex items-center justify-between gap-4 py-3 first:pt-0">
                    <dt class="text-sm text-zinc-500">{{ __('Method') }}</dt>
                    <dd class="font-medium">{{ $monitor->method }}</dd>
                </div>
                <div class="flex items-center justify-between gap-4 py-3">
                    <dt class="text-sm text-zinc-500">{{ __('Expected HTTP status') }}</dt>
                    <dd class="font-medium">{{ $monitor->expected_status_code }}</dd>
                </div>
                <div class="flex items-center justify-between gap-4 py-3">
                    <dt class="text-sm text-zinc-500">{{ __('Check interval') }}</dt>
                    <dd class="font-medium">{{ $monitor->interval_seconds / 60 }} min</dd>
                </div>
                <div class="flex items-center justify-between gap-4 py-3 last:pb-0">
                    <dt class="text-sm text-zinc-500">{{ __('Request timeout') }}</dt>
                    <dd class="font-medium">{{ $monitor->timeout_seconds }} s</dd>
                </div>
            </dl>
        </flux:card>
    </div>

    <x-confirmation-modal
        name="delete-monitor"
        :title="__('Delete website?')"
        :description="__('This will permanently remove the website, its checks, incidents, and status-page associations. This action cannot be undone.')"
        action="delete"
        :confirm-label="__('Delete website')"
    />
</section>
