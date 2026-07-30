<?php

use App\Actions\Monitors\DeleteMonitor;
use App\Actions\Monitors\PauseMonitor;
use App\Actions\Monitors\ResumeMonitor;
use App\Enums\MonitorStatus;
use App\Models\Monitor;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Websites')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = 'all';

    #[Locked]
    public ?int $pendingDeletionId = null;

    public function mount(): void
    {
        Gate::authorize('viewAny', Monitor::class);
    }

    #[Computed]
    public function monitors(): LengthAwarePaginator
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        $status = MonitorStatus::tryFrom($this->status);
        $search = trim($this->search);

        return $user->monitors()
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->whereLike('name', "%{$search}%")
                        ->orWhereLike('url', "%{$search}%");
                });
            })
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->latest()
            ->paginate(15);
    }

    #[Computed]
    public function hasMonitors(): bool
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user->monitors()->exists();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'status');
        $this->resetPage();
    }

    public function pause(int $monitorId, PauseMonitor $pauseMonitor): void
    {
        $monitor = $this->ownedMonitor($monitorId);

        Gate::authorize('pause', $monitor);
        $pauseMonitor->handle($monitor);

        Flux::toast(variant: 'success', text: __('Website paused.'));
    }

    public function resume(int $monitorId, ResumeMonitor $resumeMonitor): void
    {
        $monitor = $this->ownedMonitor($monitorId);

        Gate::authorize('resume', $monitor);
        $resumeMonitor->handle($monitor);

        Flux::toast(variant: 'success', text: __('Website resumed.'));
    }

    public function delete(int $monitorId, DeleteMonitor $deleteMonitor): void
    {
        $monitor = $this->ownedMonitor($monitorId);

        Gate::authorize('delete', $monitor);
        $deleteMonitor->handle($monitor);

        Flux::toast(variant: 'success', text: __('Website deleted.'));
    }

    public function confirmDelete(int $monitorId): void
    {
        $monitor = $this->ownedMonitor($monitorId);

        Gate::authorize('delete', $monitor);
        $this->pendingDeletionId = $monitor->id;

        Flux::modal('delete-monitor')->show();
    }

    public function deletePending(DeleteMonitor $deleteMonitor): void
    {
        abort_if($this->pendingDeletionId === null, 404);

        $this->delete($this->pendingDeletionId, $deleteMonitor);
        $this->pendingDeletionId = null;
        Flux::modal('delete-monitor')->close();
    }

    private function ownedMonitor(int $monitorId): Monitor
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user->monitors()->findOrFail($monitorId);
    }
};
?>

<section class="w-full space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl">{{ __('Websites') }}</flux:heading>
            <flux:subheading>{{ __('Control the availability and response time of your websites.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" icon="plus" :href="route('monitors.create')" wire:navigate>
            {{ __('Add website') }}
        </flux:button>
    </div>

    @if ($this->hasMonitors)
        <flux:card class="flex flex-col gap-4 sm:flex-row sm:items-end">
            <flux:input
                class="flex-1"
                wire:model.live.debounce.300ms="search"
                icon="magnifying-glass"
                :label="__('Search websites')"
                :placeholder="__('Search by name or URL')"
                clearable
            />
            <flux:select wire:model.live="status" :label="__('Status')" class="sm:w-52">
                <flux:select.option value="all">{{ __('All statuses') }}</flux:select.option>
                @foreach (MonitorStatus::cases() as $statusOption)
                    <flux:select.option :value="$statusOption->value">
                        {{ $statusOption->label() }}
                    </flux:select.option>
                @endforeach
            </flux:select>
        </flux:card>
    @endif

    @if (! $this->hasMonitors)
        <flux:card class="flex flex-col items-center gap-4 py-12 text-center">
            <div class="rounded-full bg-zinc-100 p-3 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                <flux:icon.signal class="size-7" />
            </div>
            <div>
                <flux:heading>{{ __('No websites yet') }}</flux:heading>
                <flux:text>{{ __('Add your first website to start monitoring it.') }}</flux:text>
            </div>
            <flux:button variant="primary" icon="plus" :href="route('monitors.create')" wire:navigate>
                {{ __('Add website') }}
            </flux:button>
        </flux:card>
    @elseif ($this->monitors->isEmpty())
        <flux:card class="flex flex-col items-center gap-4 py-12 text-center">
            <div class="rounded-full bg-zinc-100 p-3 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                <flux:icon.magnifying-glass class="size-7" />
            </div>
            <div>
                <flux:heading>{{ __('No matching websites') }}</flux:heading>
                <flux:text>{{ __('Try a different search term or status filter.') }}</flux:text>
            </div>
            <flux:button wire:click="resetFilters">{{ __('Clear filters') }}</flux:button>
        </flux:card>
    @else
        <flux:card>
            <flux:table :paginate="$this->monitors">
                <flux:table.columns>
                    <flux:table.column class="ps-4!">{{ __('Website') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column>{{ __('Last checked') }}</flux:table.column>
                    <flux:table.column>{{ __('Interval') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Actions') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->monitors as $monitor)
                        <flux:table.row :key="$monitor->id">
                            <flux:table.cell variant="strong" class="ps-4!">
                                <a href="{{ route('monitors.show', $monitor) }}" class="flex items-center gap-3" wire:navigate>
                                    <x-monitors.favicon :monitor="$monitor" />
                                    <span class="min-w-0">
                                        <span class="block">{{ $monitor->name }}</span>
                                        <span class="block max-w-md truncate text-xs font-normal text-zinc-500">{{ $monitor->url }}</span>
                                    </span>
                                </a>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:badge :color="$monitor->status->color()" size="sm">
                                    {{ $monitor->status->label() }}
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>
                                {{ $monitor->last_checked_at?->diffForHumans() ?? __('Not checked yet') }}
                            </flux:table.cell>
                            <flux:table.cell>{{ $monitor->interval_seconds / 60 }} min</flux:table.cell>
                            <flux:table.cell align="end">
                                <div class="flex justify-end gap-1">
                                    <flux:button
                                        variant="ghost"
                                        size="sm"
                                        icon="pencil-square"
                                        :href="route('monitors.edit', $monitor)"
                                        :aria-label="__('Edit website')"
                                        wire:navigate
                                    />

                                    @if ($monitor->is_active)
                                        <flux:button
                                            variant="ghost"
                                            size="sm"
                                            icon="pause"
                                            :aria-label="__('Pause website')"
                                            wire:click="pause({{ $monitor->id }})"
                                        />
                                    @else
                                        <flux:button
                                            variant="ghost"
                                            size="sm"
                                            icon="play"
                                            :aria-label="__('Resume website')"
                                            wire:click="resume({{ $monitor->id }})"
                                        />
                                    @endif

                                    <flux:button
                                        variant="danger"
                                        size="sm"
                                        icon="trash"
                                        :aria-label="__('Delete website')"
                                        wire:click="confirmDelete({{ $monitor->id }})"
                                    />
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </flux:card>
    @endif

    <x-confirmation-modal
        name="delete-monitor"
        :title="__('Delete website?')"
        :description="__('This will permanently remove the website, its checks, incidents, and status-page associations. This action cannot be undone.')"
        action="deletePending"
        :confirm-label="__('Delete website')"
    />
</section>
