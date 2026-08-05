<?php

use App\Actions\Monitors\UpdateMonitor;
use App\Models\Monitor;
use App\Rules\SafePublicUrl;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Edit website')] class extends Component
{
    #[Locked]
    public Monitor $monitor;

    public string $name = '';

    public string $url = '';

    public int $expected_status_code = 200;

    public int $interval_seconds = 300;

    public int $timeout_seconds = 10;

    public function mount(Monitor $monitor): void
    {
        Gate::authorize('update', $monitor);

        $this->monitor = $monitor;
        $this->name = $monitor->name;
        $this->url = $monitor->url;
        $this->expected_status_code = $monitor->expected_status_code;
        $this->interval_seconds = $monitor->interval_seconds;
        $this->timeout_seconds = $monitor->timeout_seconds;
    }

    public function save(UpdateMonitor $updateMonitor): void
    {
        Gate::authorize('update', $this->monitor);

        $this->url = Str::of($this->url)->trim()->toString();
        $updateMonitor->handle($this->monitor, $this->validate($this->rules()));

        $this->redirectRoute('monitors.show', ['monitor' => $this->monitor], navigate: true);
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'url' => ['required', 'string', 'max:2048', 'url:http,https', app(SafePublicUrl::class)],
            'expected_status_code' => ['required', 'integer', 'between:100,599'],
            'interval_seconds' => ['required', 'integer', Rule::in([60, 300, 600, 900, 1800, 3600])],
            'timeout_seconds' => ['required', 'integer', 'min:1', 'max:'.config('monitoring.max_timeout_seconds', 60)],
        ];
    }
};
?>

<section class="w-full max-w-3xl space-y-6">
    <div>
        <flux:heading size="xl">{{ __('Edit website') }}</flux:heading>
        <flux:subheading>{{ __('Update how this website will be checked.') }}</flux:subheading>
    </div>

    <flux:card>
        <form wire:submit="save" class="space-y-8">
            <x-monitors.form-fields />

            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <flux:button class="w-full sm:w-auto" :href="route('monitors.show', $monitor)" wire:navigate>{{ __('Cancel') }}</flux:button>
                <flux:button class="w-full sm:w-auto" variant="primary" type="submit" data-test="update-monitor-button">
                    {{ __('Save changes') }}
                </flux:button>
            </div>
        </form>
    </flux:card>
</section>
