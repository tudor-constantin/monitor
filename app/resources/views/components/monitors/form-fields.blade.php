<div class="grid gap-6 sm:grid-cols-2">
    <div class="sm:col-span-2">
        <flux:input
            wire:model="name"
            :label="__('Name')"
            :description="__('A recognizable name for this website.')"
            placeholder="Acme website"
            required
            autofocus
        />
    </div>

    <div class="sm:col-span-2">
        <flux:input
            wire:model="url"
            :label="__('URL')"
            :description="__('Only public HTTP and HTTPS addresses are accepted.')"
            type="url"
            placeholder="https://example.com/"
            required
        />
    </div>

    <flux:select wire:model="interval_seconds" :label="__('Check interval')" required>
        <flux:select.option value="60">{{ __('Every minute') }}</flux:select.option>
        <flux:select.option value="300">{{ __('Every 5 minutes') }}</flux:select.option>
        <flux:select.option value="600">{{ __('Every 10 minutes') }}</flux:select.option>
        <flux:select.option value="900">{{ __('Every 15 minutes') }}</flux:select.option>
        <flux:select.option value="1800">{{ __('Every 30 minutes') }}</flux:select.option>
        <flux:select.option value="3600">{{ __('Every hour') }}</flux:select.option>
    </flux:select>

    <flux:input
        wire:model="timeout_seconds"
        :label="__('Timeout (seconds)')"
        type="number"
        min="1"
        max="60"
        required
    />

    <flux:input
        wire:model="expected_status_code"
        :label="__('Expected HTTP status')"
        type="number"
        min="100"
        max="599"
        required
    />

    <flux:input :label="__('Method')" value="GET" disabled />
</div>
