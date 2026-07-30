@props(['monitors', 'monitorSearch', 'selectedCount'])

<div class="space-y-8">
    <div class="grid gap-6">
        <flux:field>
            <flux:label>{{ __('Name') }}</flux:label>
            <flux:input wire:model="name" required maxlength="100" placeholder="{{ __('Public service status') }}" />
            <flux:error name="name" />
        </flux:field>

        <flux:field>
            <flux:label>{{ __('Description') }}</flux:label>
            <flux:textarea
                wire:model="description"
                rows="4"
                maxlength="1000"
                placeholder="{{ __('Current availability and incident updates for our services.') }}"
            />
            <flux:description>{{ __('Optional context displayed at the top of the public page.') }}</flux:description>
            <flux:error name="description" />
        </flux:field>

        <flux:switch
            wire:model="is_public"
            :label="__('Publish this status page')"
            :description="__('Anyone with the public URL can view a published page.')"
        />
        <flux:error name="is_public" />
    </div>

    <flux:separator />

    <div class="space-y-4">
        <div>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <flux:heading>{{ __('Included websites') }}</flux:heading>
                <flux:badge color="zinc">
                    {{ trans_choice(':count selected|:count selected', $selectedCount, ['count' => number_format($selectedCount)]) }}
                </flux:badge>
            </div>
            <flux:text>{{ __('Choose which websites are visible. Their monitoring data remains private everywhere else.') }}</flux:text>
        </div>

        <flux:input
            wire:model.live.debounce.300ms="monitorSearch"
            icon="magnifying-glass"
            :placeholder="__('Search websites by name or URL')"
            clearable
        />

        @if ($monitors->isEmpty() && $monitorSearch === '')
            <flux:callout icon="information-circle" color="amber">
                <flux:callout.heading>{{ __('No websites available') }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __('Add a website before configuring a status page.') }}
                </flux:callout.text>
            </flux:callout>
        @elseif ($monitors->isEmpty())
            <div class="rounded-xl border border-dashed border-zinc-300 px-6 py-8 text-center dark:border-zinc-700">
                <flux:heading>{{ __('No matching websites') }}</flux:heading>
                <flux:text>{{ __('Try a different name or URL.') }}</flux:text>
            </div>
        @else
            <flux:checkbox.group wire:model="selectedMonitorIds" class="grid min-w-0 gap-3 sm:grid-cols-2">
                @foreach ($monitors as $monitor)
                    <div
                        class="flex min-w-0 max-w-full items-start gap-3 overflow-hidden rounded-xl border border-zinc-200 p-4 dark:border-zinc-700"
                        wire:key="status-page-monitor-option-{{ $monitor->id }}"
                    >
                        <x-monitors.favicon :monitor="$monitor" />
                        <div class="min-w-0 flex-1 overflow-hidden">
                            <flux:checkbox
                                class="min-w-0 [&_[data-flux-description]]:break-all [&_[data-flux-label]]:break-words [&_[data-flux-label]]:whitespace-normal"
                                :value="$monitor->id"
                                :label="$monitor->name"
                                :description="$monitor->url"
                            />
                        </div>
                    </div>
                @endforeach
            </flux:checkbox.group>

            <div>{{ $monitors->links(data: ['scrollTo' => false]) }}</div>
        @endif

        <flux:error name="selectedMonitorIds" />
        <flux:error name="selectedMonitorIds.*" />
    </div>
</div>
