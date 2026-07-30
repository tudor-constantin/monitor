@props(['monitor'])

<span
    {{ $attributes->class([
        'flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-zinc-100 text-zinc-500 ring-1 ring-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:ring-zinc-700',
    ]) }}
>
    @if ($monitor->faviconUrl() !== null)
        <img
            src="{{ $monitor->faviconUrl() }}"
            alt=""
            class="size-full object-contain p-1.5"
            loading="lazy"
        />
    @else
        <flux:icon.globe-alt class="size-5" />
    @endif
</span>
