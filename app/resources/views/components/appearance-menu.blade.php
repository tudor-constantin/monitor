<flux:dropdown x-data position="top" align="start">
    <flux:sidebar.item as="button" icon:trailing="chevron-up-down" :tooltip="__('Color scheme')">
        <x-slot:icon>
            <span class="relative block size-4">
                <flux:icon.sun
                    x-show="$flux.appearance === 'light'"
                    class="absolute inset-0 size-4"
                    style="display: none"
                />
                <flux:icon.moon
                    x-show="$flux.appearance === 'dark'"
                    class="absolute inset-0 size-4"
                    style="display: none"
                />
                <flux:icon.computer-desktop
                    x-show="!['light', 'dark'].includes($flux.appearance)"
                    class="absolute inset-0 size-4"
                />
            </span>
        </x-slot:icon>

        <span
            x-text="$flux.appearance === 'light' ? '{{ __('Light') }}' : ($flux.appearance === 'dark' ? '{{ __('Dark') }}' : '{{ __('System') }}')"
        >{{ __('System') }}</span>
    </flux:sidebar.item>

    <flux:menu class="min-w-44">
        <flux:menu.item icon="sun" x-on:click="$flux.appearance = 'light'">
            <span class="flex-1">{{ __('Light') }}</span>
            <flux:icon.check x-show="$flux.appearance === 'light'" class="size-4" />
        </flux:menu.item>
        <flux:menu.item icon="moon" x-on:click="$flux.appearance = 'dark'">
            <span class="flex-1">{{ __('Dark') }}</span>
            <flux:icon.check x-show="$flux.appearance === 'dark'" class="size-4" />
        </flux:menu.item>
        <flux:menu.item icon="computer-desktop" x-on:click="$flux.appearance = 'system'">
            <span class="flex-1">{{ __('System') }}</span>
            <flux:icon.check x-show="!['light', 'dark'].includes($flux.appearance)" class="size-4" />
        </flux:menu.item>
    </flux:menu>
</flux:dropdown>
