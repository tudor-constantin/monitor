<?php

use App\Actions\StatusPages\DeleteStatusPage;
use App\Models\StatusPage;
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

new #[Title('Status pages')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Locked]
    public ?int $pendingDeletionId = null;

    public function mount(): void
    {
        Gate::authorize('viewAny', StatusPage::class);
    }

    #[Computed]
    public function statusPages(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return $this->user()
            ->statusPages()
            ->when($search !== '', fn ($query) => $query->whereLike('name', "%{$search}%"))
            ->withCount('monitors')
            ->latest()
            ->paginate(10);
    }

    #[Computed]
    public function hasStatusPages(): bool
    {
        return $this->user()->statusPages()->exists();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function delete(int $statusPageId, DeleteStatusPage $deleteStatusPage): void
    {
        $statusPage = $this->user()->statusPages()->findOrFail($statusPageId);

        Gate::authorize('delete', $statusPage);
        $deleteStatusPage->handle($statusPage);

        Flux::toast(variant: 'success', text: __('Status page deleted.'));
    }

    public function confirmDelete(int $statusPageId): void
    {
        $statusPage = $this->user()->statusPages()->findOrFail($statusPageId);

        Gate::authorize('delete', $statusPage);
        $this->pendingDeletionId = $statusPage->id;

        Flux::modal('delete-status-page')->show();
    }

    public function deletePending(DeleteStatusPage $deleteStatusPage): void
    {
        abort_if($this->pendingDeletionId === null, 404);

        $this->delete($this->pendingDeletionId, $deleteStatusPage);
        $this->pendingDeletionId = null;
        Flux::modal('delete-status-page')->close();
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
            <flux:heading size="xl">{{ __('Status pages') }}</flux:heading>
            <flux:subheading>{{ __('Share selected service health without exposing your private workspace.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" icon="plus" :href="route('status-pages.create')" wire:navigate>
            {{ __('New status page') }}
        </flux:button>
    </div>

    @if ($this->hasStatusPages)
        <flux:card>
            <flux:input
                wire:model.live.debounce.300ms="search"
                icon="magnifying-glass"
                :label="__('Search status pages')"
                :placeholder="__('Search by name')"
                clearable
            />
        </flux:card>
    @endif

    @if (! $this->hasStatusPages)
        <flux:card class="flex flex-col items-center gap-4 py-12 text-center">
            <div class="rounded-full bg-zinc-100 p-3 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                <flux:icon.rectangle-stack class="size-7" />
            </div>
            <div>
                <flux:heading>{{ __('No status pages yet') }}</flux:heading>
                <flux:text>{{ __('Create a public, read-only view for your users and customers.') }}</flux:text>
            </div>
            <flux:button variant="primary" icon="plus" :href="route('status-pages.create')" wire:navigate>
                {{ __('Create status page') }}
            </flux:button>
        </flux:card>
    @elseif ($this->statusPages->isEmpty())
        <flux:card class="py-12 text-center">
            <flux:heading>{{ __('No matching status pages') }}</flux:heading>
            <flux:text>{{ __('Try a different search term.') }}</flux:text>
        </flux:card>
    @else
        <div class="grid gap-4 lg:grid-cols-2">
            @foreach ($this->statusPages as $statusPage)
                <flux:card class="space-y-5" wire:key="status-page-{{ $statusPage->id }}">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <flux:heading class="truncate">{{ $statusPage->name }}</flux:heading>
                            <flux:text class="mt-1 line-clamp-2">
                                {{ $statusPage->description ?? __('A live view of selected service availability.') }}
                            </flux:text>
                        </div>
                        <flux:badge :color="$statusPage->is_public ? 'green' : 'zinc'" size="sm">
                            {{ $statusPage->is_public ? __('Published') : __('Draft') }}
                        </flux:badge>
                    </div>

                    <div class="flex items-center gap-2 text-sm text-zinc-500">
                        <flux:icon.signal class="size-4" />
                        {{ trans_choice(':count website|:count websites', $statusPage->monitors_count, ['count' => $statusPage->monitors_count]) }}
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                        <div class="flex gap-2">
                            <flux:button
                                size="sm"
                                icon="pencil-square"
                                :href="route('status-pages.edit', $statusPage)"
                                wire:navigate
                            >
                                {{ __('Edit') }}
                            </flux:button>
                            <flux:button
                                size="sm"
                                variant="ghost"
                                icon="arrow-top-right-on-square"
                                :href="route('status-pages.public', $statusPage)"
                                target="_blank"
                            >
                                {{ $statusPage->is_public ? __('Open') : __('Preview') }}
                            </flux:button>
                        </div>
                        <flux:button
                            size="sm"
                            variant="danger"
                            icon="trash"
                            :aria-label="__('Delete status page')"
                            wire:click="confirmDelete({{ $statusPage->id }})"
                        />
                    </div>
                </flux:card>
            @endforeach
        </div>

        <div>{{ $this->statusPages->links() }}</div>
    @endif

    <x-confirmation-modal
        name="delete-status-page"
        :title="__('Delete status page?')"
        :description="__('This will permanently remove the status page and its public URL. Websites and their history will not be deleted.')"
        action="deletePending"
        :confirm-label="__('Delete status page')"
    />
</section>
