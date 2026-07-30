<?php

use App\Actions\Monitors\CreateMonitor;
use App\Models\Monitor;
use App\Models\User;
use App\Rules\SafePublicUrl;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Add website')] class extends Component
{
    public string $name = '';

    public string $url = 'https://';

    public int $expected_status_code = 200;

    public int $interval_seconds = 300;

    public int $timeout_seconds = 10;

    public function mount(): void
    {
        Gate::authorize('create', Monitor::class);
    }

    public function save(CreateMonitor $createMonitor): void
    {
        Gate::authorize('create', Monitor::class);

        $this->url = Str::of($this->url)->trim()->toString();
        $validated = $this->validate($this->rules());
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        $monitor = $createMonitor->handle($user, $validated);

        $this->redirectRoute('monitors.show', ['monitor' => $monitor], navigate: true);
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'url' => ['required', 'string', 'max:2048', 'url:http,https', new SafePublicUrl],
            'expected_status_code' => ['required', 'integer', 'between:100,599'],
            'interval_seconds' => ['required', 'integer', Rule::in([60, 300, 600, 900, 1800, 3600])],
            'timeout_seconds' => ['required', 'integer', 'between:1,60'],
        ];
    }
};
?>

<section class="w-full max-w-3xl space-y-6">
    <div>
        <flux:heading size="xl">{{ __('Add website') }}</flux:heading>
        <flux:subheading>{{ __('Add a public website that Monitor will check periodically.') }}</flux:subheading>
    </div>

    <flux:card>
        <form wire:submit="save" class="space-y-8">
            <x-monitors.form-fields />

            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <flux:button class="w-full sm:w-auto" :href="route('monitors.index')" wire:navigate>{{ __('Cancel') }}</flux:button>
                <flux:button class="w-full sm:w-auto" variant="primary" type="submit" data-test="create-monitor-button">
                    {{ __('Add website') }}
                </flux:button>
            </div>
        </form>
    </flux:card>
</section>
