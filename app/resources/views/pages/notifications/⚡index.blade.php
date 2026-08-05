<?php

use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Notifications')] class extends Component
{
    use WithPagination;

    #[Url]
    public string $filter = 'all';

    public function mount(): void
    {
        $this->filter = $this->clampFilter($this->filter);
    }

    public function hydrate(): void
    {
        // #[Url] repopulates this from the query string on every request, so an
        // unknown ?filter= would otherwise leave neither radio selected while
        // the list quietly showed everything.
        $this->filter = $this->clampFilter($this->filter);
    }

    #[Computed]
    public function notifications(): LengthAwarePaginator
    {
        return $this->user()
            ->notifications()
            ->when($this->filter === 'unread', fn ($query) => $query->whereNull('read_at'))
            ->paginate(15);
    }

    private function clampFilter(string $filter): string
    {
        return in_array($filter, ['all', 'unread'], true) ? $filter : 'all';
    }

    #[Computed]
    public function unreadCount(): int
    {
        return $this->user()->unreadNotifications()->count();
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function markAsRead(string $notificationId): void
    {
        $this->ownedNotification($notificationId)->markAsRead();

        unset($this->notifications, $this->unreadCount);
    }

    public function markAllAsRead(): void
    {
        $this->user()->unreadNotifications()->update(['read_at' => now()]);

        unset($this->notifications, $this->unreadCount);

        Flux::toast(variant: 'success', text: __('All notifications marked as read.'));
    }

    public function delete(string $notificationId): void
    {
        $this->ownedNotification($notificationId)->delete();

        unset($this->notifications, $this->unreadCount);

        Flux::toast(variant: 'success', text: __('Notification deleted.'));
    }

    /**
     * Always scope by the authenticated user so an ID from another account
     * cannot be read or deleted.
     */
    private function ownedNotification(string $notificationId): DatabaseNotification
    {
        /** @var DatabaseNotification */
        return $this->user()->notifications()->whereKey($notificationId)->firstOrFail();
    }

    private function user(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
};
?>

<section class="w-full space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl">{{ __('Notifications') }}</flux:heading>
            <flux:subheading>{{ __('Outage and recovery alerts stored in Monitor.') }}</flux:subheading>
        </div>

        <div class="flex flex-wrap gap-2">
            @if ($this->unreadCount > 0)
                <flux:button icon="check" wire:click="markAllAsRead">
                    {{ __('Mark all as read') }}
                </flux:button>
            @endif
            <flux:button icon="cog-6-tooth" :href="route('notifications.edit')" wire:navigate>
                {{ __('Preferences') }}
            </flux:button>
        </div>
    </div>

    <flux:card>
        <flux:radio.group wire:model.live="filter" variant="segmented" :label="__('Filter notifications')">
            <flux:radio value="all">{{ __('All') }}</flux:radio>
            <flux:radio value="unread">
                {{ __('Unread') }}@if ($this->unreadCount > 0) ({{ $this->unreadCount }}) @endif
            </flux:radio>
        </flux:radio.group>
    </flux:card>

    @if ($this->notifications->isEmpty())
        <flux:card class="flex flex-col items-center gap-4 py-12 text-center">
            <div class="rounded-full bg-zinc-100 p-3 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                <flux:icon.bell class="size-7" />
            </div>
            <div>
                <flux:heading>
                    {{ $filter === 'unread' ? __('No unread notifications') : __('No notifications yet') }}
                </flux:heading>
                <flux:text>
                    {{ $filter === 'unread'
                        ? __('You are all caught up.')
                        : __('Alerts will appear here when one of your websites goes down or recovers.') }}
                </flux:text>
            </div>
        </flux:card>
    @else
        <flux:card class="p-0">
            <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($this->notifications as $notification)
                    @php($data = $notification->data)
                    @php($isDown = ($data['status'] ?? null) === 'down')
                    <li
                        @class([
                            'flex flex-col gap-3 p-5 sm:flex-row sm:items-start sm:justify-between',
                            'bg-zinc-50 dark:bg-zinc-900/40' => $notification->read_at === null,
                        ])
                        wire:key="notification-{{ $notification->id }}"
                    >
                        <div class="flex min-w-0 items-start gap-3">
                            <div @class([
                                'mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full',
                                'bg-red-100 text-red-600 dark:bg-red-950 dark:text-red-400' => $isDown,
                                'bg-emerald-100 text-emerald-600 dark:bg-emerald-950 dark:text-emerald-400' => ! $isDown,
                            ])>
                                @if ($isDown)
                                    <flux:icon.exclamation-triangle class="size-5" />
                                @else
                                    <flux:icon.check-circle class="size-5" />
                                @endif
                            </div>

                            <div class="min-w-0 space-y-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-medium">{{ $data['monitor_name'] ?? __('Website') }}</span>
                                    <flux:badge size="sm" :color="$isDown ? 'red' : 'green'">
                                        {{ $isDown ? __('Down') : __('Recovered') }}
                                    </flux:badge>
                                    @if ($notification->read_at === null)
                                        <flux:badge size="sm" color="blue">{{ __('New') }}</flux:badge>
                                    @endif
                                </div>

                                <flux:text class="break-all">{{ $data['monitor_url'] ?? '' }}</flux:text>

                                @if ($isDown && filled($data['cause'] ?? null))
                                    <flux:text>{{ $data['cause'] }}</flux:text>
                                @elseif (! $isDown && ($data['duration_seconds'] ?? null) !== null)
                                    <flux:text>
                                        {{ __('Downtime: :seconds seconds', ['seconds' => $data['duration_seconds']]) }}
                                    </flux:text>
                                @endif

                                <flux:text>
                                    <time datetime="{{ $notification->created_at?->toIso8601String() }}">
                                        {{ $notification->created_at?->diffForHumans() }}
                                    </time>
                                </flux:text>
                            </div>
                        </div>

                        <div class="flex shrink-0 gap-1">
                            @if ($notification->read_at === null)
                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    icon="check"
                                    :aria-label="__('Mark as read')"
                                    wire:click="markAsRead('{{ $notification->id }}')"
                                />
                            @endif
                            @isset($data['monitor_id'])
                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    icon="arrow-top-right-on-square"
                                    :aria-label="__('View website')"
                                    :href="route('monitors.show', $data['monitor_id'])"
                                    wire:navigate
                                />
                            @endisset
                            <flux:button
                                variant="danger"
                                size="sm"
                                icon="trash"
                                :aria-label="__('Delete notification')"
                                wire:click="delete('{{ $notification->id }}')"
                            />
                        </div>
                    </li>
                @endforeach
            </ul>

            <div class="p-4">
                {{ $this->notifications->links() }}
            </div>
        </flux:card>
    @endif
</section>
