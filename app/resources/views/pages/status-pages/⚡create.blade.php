<?php

use App\Actions\StatusPages\CreateStatusPage;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Create status page')] class extends Component
{
    use WithPagination;

    public string $name = '';

    public string $description = '';

    public bool $is_public = false;

    /** @var list<int|string> */
    public array $selectedMonitorIds = [];

    public string $monitorSearch = '';

    public function mount(): void
    {
        Gate::authorize('create', StatusPage::class);
    }

    /**
     */
    #[Computed]
    public function availableMonitors(): LengthAwarePaginator
    {
        $search = trim($this->monitorSearch);

        return $this->user()
            ->monitors()
            ->whereDoesntHave('statusPages')
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

    public function save(CreateStatusPage $createStatusPage): void
    {
        Gate::authorize('create', StatusPage::class);

        $validated = $this->validate($this->rules());
        $statusPage = $createStatusPage->handle(
            $this->user(),
            [
                'name' => $validated['name'],
                'description' => filled($validated['description']) ? trim($validated['description']) : null,
                'is_public' => $validated['is_public'],
            ],
            array_map(intval(...), $validated['selectedMonitorIds']),
        );

        $this->redirectRoute('status-pages.edit', ['statusPage' => $statusPage], navigate: true);
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rules(): array
    {
        $user = $this->user();

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
                    ->where('user_id', $user->id),
            ],
        ];
    }

    private function user(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
};
?>

<section class="w-full max-w-3xl space-y-6">
    <div>
        <flux:heading size="xl">{{ __('Create status page') }}</flux:heading>
        <flux:subheading>{{ __('Publish a focused, read-only view of selected services.') }}</flux:subheading>
    </div>

    <flux:card class="min-w-0 max-w-full overflow-hidden">
        <form wire:submit="save" class="space-y-8">
            <x-status-pages.form-fields
                :monitors="$this->availableMonitors"
                :monitor-search="$monitorSearch"
                :selected-count="count($selectedMonitorIds)"
            />

            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <flux:button class="w-full sm:w-auto" :href="route('status-pages.index')" wire:navigate>{{ __('Cancel') }}</flux:button>
                <flux:button
                    class="w-full sm:w-auto"
                    variant="primary"
                    type="submit"
                    icon="globe-alt"
                    :disabled="$selectedMonitorIds === []"
                >
                    {{ __('Create status page') }}
                </flux:button>
            </div>
        </form>
    </flux:card>
</section>
