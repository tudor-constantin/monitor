<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white text-zinc-900 antialiased dark:bg-zinc-950 dark:text-white">
        <div class="mx-auto flex min-h-screen max-w-6xl flex-col px-6">
            <header class="flex h-20 items-center justify-between">
                <a href="{{ route('home') }}" class="flex items-center gap-3 font-semibold">
                    <span class="flex size-10 items-center justify-center rounded-xl bg-zinc-900 text-white dark:bg-white dark:text-zinc-900">
                        <x-app-logo-icon class="size-6" />
                    </span>
                    <span>{{ config('app.name') }}</span>
                </a>

                @if (Route::has('login'))
                    <nav class="flex items-center gap-3">
                        @auth
                            <flux:button :href="route('dashboard')" wire:navigate>{{ __('Dashboard') }}</flux:button>
                        @else
                            <flux:button variant="ghost" :href="route('login')" wire:navigate>{{ __('Log in') }}</flux:button>

                            @if (Route::has('register'))
                                <flux:button variant="primary" :href="route('register')" wire:navigate>{{ __('Get started') }}</flux:button>
                            @endif
                        @endauth
                    </nav>
                @endif
            </header>

            <main class="grid flex-1 items-center gap-12 py-16 lg:grid-cols-[1.1fr_0.9fr]">
                <div class="max-w-2xl">
                    <flux:badge color="green" icon="signal">{{ __('Website monitoring') }}</flux:badge>
                    <h1 class="mt-6 text-5xl font-semibold tracking-tight text-balance sm:text-6xl">
                        {{ __('Know when your websites need attention.') }}
                    </h1>
                    <p class="mt-6 max-w-xl text-lg leading-8 text-zinc-600 dark:text-zinc-300">
                        {{ __('Monitor checks your public endpoints, tracks response times, and will alert you when a service goes down or recovers.') }}
                    </p>
                    <div class="mt-8 flex flex-wrap gap-3">
                        @auth
                            <flux:button variant="primary" icon-trailing="arrow-right" :href="route('monitors.index')" wire:navigate>
                                {{ __('View websites') }}
                            </flux:button>
                        @else
                            @if (Route::has('register'))
                                <flux:button variant="primary" icon-trailing="arrow-right" :href="route('register')" wire:navigate>
                                    {{ __('Create an account') }}
                                </flux:button>
                            @endif

                            <flux:button :href="route('login')" wire:navigate>{{ __('Log in') }}</flux:button>
                        @endauth
                    </div>
                </div>

                <div class="relative">
                    <div class="absolute inset-0 -z-10 rounded-full bg-emerald-400/15 blur-3xl"></div>
                    <div class="rounded-3xl border border-zinc-200 bg-zinc-50 p-6 shadow-2xl shadow-zinc-950/10 dark:border-zinc-800 dark:bg-zinc-900">
                        <div class="flex items-center justify-between border-b border-zinc-200 pb-5 dark:border-zinc-800">
                            <div>
                                <div class="font-medium">monitor.example.com</div>
                                <div class="text-sm text-zinc-500">{{ __('Checked just now') }}</div>
                            </div>
                            <flux:badge color="green">{{ __('Operational') }}</flux:badge>
                        </div>
                        <div class="grid grid-cols-2 gap-4 py-6">
                            <div class="rounded-2xl bg-white p-4 dark:bg-zinc-800">
                                <div class="text-sm text-zinc-500">{{ __('Response time') }}</div>
                                <div class="mt-2 text-3xl font-semibold">184 ms</div>
                            </div>
                            <div class="rounded-2xl bg-white p-4 dark:bg-zinc-800">
                                <div class="text-sm text-zinc-500">{{ __('Uptime') }}</div>
                                <div class="mt-2 text-3xl font-semibold">99.99%</div>
                            </div>
                        </div>
                        <svg class="h-28 w-full text-emerald-500" viewBox="0 0 400 112" fill="none" aria-hidden="true">
                            <path d="M0 91C42 84 52 46 90 55s51 33 89 12 47-51 84-37 43 55 76 34 36-32 61-29" stroke="currentColor" stroke-width="5" stroke-linecap="round" />
                            <path d="M0 91C42 84 52 46 90 55s51 33 89 12 47-51 84-37 43 55 76 34 36-32 61-29V112H0Z" fill="currentColor" opacity=".12" />
                        </svg>
                    </div>
                </div>
            </main>
        </div>

        @fluxScripts
    </body>
</html>
