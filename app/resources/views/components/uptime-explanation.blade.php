<span {{ $attributes->class('inline-flex') }}>
    <flux:tooltip toggleable hover position="top" align="start">
        <button
            type="button"
            class="inline-flex items-center gap-1 rounded-md text-sm text-zinc-500 outline-none transition hover:text-zinc-800 focus-visible:ring-2 focus-visible:ring-zinc-900 focus-visible:ring-offset-2 dark:text-zinc-400 dark:hover:text-zinc-200 dark:focus-visible:ring-white dark:focus-visible:ring-offset-zinc-900"
            aria-label="{{ __('About uptime') }}"
        >
            <flux:icon.information-circle class="size-4" />
            <span>{{ __('About uptime') }}</span>
        </button>

        <flux:tooltip.content class="max-w-80 font-normal leading-relaxed">
            {{ __('Uptime is the percentage of recorded checks that completed successfully; it does not measure continuous availability.') }}
        </flux:tooltip.content>
    </flux:tooltip>
</span>
