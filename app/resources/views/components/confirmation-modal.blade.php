@props([
    'name',
    'title',
    'description',
    'action',
    'confirmLabel' => __('Delete'),
])

<flux:modal :name="$name" class="min-w-[22rem] max-w-md">
    <div class="space-y-6">
        <div class="flex items-start gap-4">
            <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-600 dark:bg-red-950 dark:text-red-400">
                <flux:icon.exclamation-triangle class="size-5" />
            </div>
            <div>
                <flux:heading size="lg">{{ $title }}</flux:heading>
                <flux:text class="mt-2">{{ $description }}</flux:text>
            </div>
        </div>

        <div class="flex justify-end gap-2">
            <flux:modal.close>
                <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>
            <flux:button
                variant="danger"
                wire:click="{{ $action }}"
                wire:loading.attr="disabled"
                wire:target="{{ $action }}"
            >
                {{ $confirmLabel }}
            </flux:button>
        </div>
    </div>
</flux:modal>
