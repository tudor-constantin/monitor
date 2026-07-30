<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-zinc-50 text-zinc-900 dark:bg-zinc-950 dark:text-white">
        <header class="border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">
                <x-app-logo :href="route('home')" />
                @if (request()->route('statusPage')?->is_public)
                    <flux:button
                        variant="primary"
                        icon="bell"
                        x-data
                        x-on:click="$flux.modal('subscribe-status-updates').show()"
                    >
                        {{ __('Subscribe to updates') }}
                    </flux:button>
                @else
                    <flux:badge color="amber">{{ __('Draft preview') }}</flux:badge>
                @endif
            </div>
        </header>

        {{ $slot }}

        @fluxScripts
    </body>
</html>
