<?php

use App\Actions\StatusPages\DeleteStatusPage;
use App\Actions\StatusPages\UpdateStatusPage;
use App\Models\Monitor;
use App\Models\StatusPage;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Edit status page')] class extends Component
{
    use WithPagination;

    #[Locked]
    public StatusPage $statusPage;

    public string $name = '';

    public string $description = '';

    public bool $is_public = false;

    /** @var list<int|string> */
    public array $selectedMonitorIds = [];

    public string $monitorSearch = '';

    public function mount(StatusPage $statusPage): void
    {
        Gate::authorize('update', $statusPage);

        $this->statusPage = $statusPage;
        $this->name = $statusPage->name;
        $this->description = $statusPage->description ?? '';
        $this->is_public = $statusPage->is_public;
        $this->selectedMonitorIds = $statusPage
            ->monitors()
            ->pluck((new Monitor)->qualifyColumn('id'))
            ->map(fn (mixed $monitorId): int => (int) $monitorId)
            ->all();
    }

    /**
     */
    #[Computed]
    public function availableMonitors(): LengthAwarePaginator
    {
        $search = trim($this->monitorSearch);

        return $this->statusPage
            ->user()
            ->firstOrFail()
            ->monitors()
            ->whereDoesntHave(
                'statusPages',
                fn ($query) => $query->whereKeyNot($this->statusPage->getKey()),
            )
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->whereLike('name', "%{$search}%")
                        ->orWhereLike('url', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->paginate(
                perPage: 12,
                columns: ['id', 'user_id', 'name', 'url', 'favicon_path', 'favicon_fetched_at'],
                pageName: 'monitorsPage',
            );
    }

    public function updatedMonitorSearch(): void
    {
        $this->resetPage(pageName: 'monitorsPage');
    }

    public function save(UpdateStatusPage $updateStatusPage): void
    {
        Gate::authorize('update', $this->statusPage);

        $validated = $this->validate($this->rules());
        $this->statusPage = $updateStatusPage->handle(
            $this->statusPage,
            [
                'name' => $validated['name'],
                'description' => filled($validated['description']) ? trim($validated['description']) : null,
                'is_public' => $validated['is_public'],
            ],
            array_map(intval(...), $validated['selectedMonitorIds']),
        );

        Flux::toast(variant: 'success', text: __('Status page updated.'));
    }

    public function delete(DeleteStatusPage $deleteStatusPage): void
    {
        Gate::authorize('delete', $this->statusPage);
        $deleteStatusPage->handle($this->statusPage);

        $this->redirectRoute('status-pages.index', navigate: true);
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_public' => ['required', 'boolean'],
            'selectedMonitorIds' => ['required', 'array', 'min:1'],
            'selectedMonitorIds.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists((new Monitor)->getTable(), 'id')
                    ->where('user_id', $this->statusPage->user_id),
            ],
        ];
    }
};
?>

<section class="w-full max-w-3xl space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <flux:heading size="xl">{{ __('Edit status page') }}</flux:heading>
            <flux:subheading>{{ __('Control its content and public visibility.') }}</flux:subheading>
        </div>

        <flux:button
            icon="arrow-top-right-on-square"
            :href="route('status-pages.public', $statusPage)"
            target="_blank"
        >
            {{ $statusPage->is_public ? __('View public page') : __('Preview draft') }}
        </flux:button>
    </div>

    <flux:callout icon="link">
        <flux:callout.heading>{{ __('Public URL') }}</flux:callout.heading>
        <flux:callout.text class="break-all">
            {{ route('status-pages.public', $statusPage) }}
        </flux:callout.text>
    </flux:callout>

    <flux:card class="min-w-0 max-w-full overflow-hidden">
        <form wire:submit="save" class="space-y-8">
            <x-status-pages.form-fields
                :monitors="$this->availableMonitors"
                :monitor-search="$monitorSearch"
                :selected-count="count($selectedMonitorIds)"
            />

            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
                <flux:modal.trigger name="delete-status-page">
                    <flux:button variant="danger" type="button" icon="trash">
                        {{ __('Delete status page') }}
                    </flux:button>
                </flux:modal.trigger>
                <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <flux:button class="w-full sm:w-auto" :href="route('status-pages.index')" wire:navigate>{{ __('Back') }}</flux:button>
                    <flux:button class="w-full sm:w-auto" variant="primary" type="submit">{{ __('Save changes') }}</flux:button>
                </div>
            </div>
        </form>
    </flux:card>

    <x-confirmation-modal
        name="delete-status-page"
        :title="__('Delete status page?')"
        :description="__('This will permanently remove the status page and its public URL. Websites and their history will not be deleted.')"
        action="delete"
        :confirm-label="__('Delete status page')"
    />
</section>
