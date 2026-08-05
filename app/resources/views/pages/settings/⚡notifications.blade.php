<?php

use App\Actions\Users\UpdateNotificationPreferences;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Notification settings')] class extends Component {
    public bool $emailNotificationsEnabled = true;

    public bool $databaseNotificationsEnabled = true;

    public function mount(): void
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        $this->emailNotificationsEnabled = $user->email_notifications_enabled;
        $this->databaseNotificationsEnabled = $user->database_notifications_enabled;
    }

    public function save(UpdateNotificationPreferences $updateNotificationPreferences): void
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        $updateNotificationPreferences->handle(
            $user,
            $this->emailNotificationsEnabled,
            $this->databaseNotificationsEnabled,
        );

        Flux::toast(variant: 'success', text: __('Notification preferences updated.'));
    }
}; ?>

<section class="w-full">
    <x-pages::settings.layout
        :heading="__('Notifications')"
        :subheading="__('Choose how Monitor alerts you when one of your websites goes down or recovers.')"
    >
        <form wire:submit="save" class="space-y-6">
            <flux:fieldset>
                <flux:legend>{{ __('Delivery channels') }}</flux:legend>

                <div class="space-y-4">
                    <flux:switch
                        wire:model="emailNotificationsEnabled"
                        :label="__('Email notifications')"
                        :description="__('Receive outage and recovery alerts at your account email address.')"
                    />

                    <flux:separator variant="subtle" />

                    <flux:switch
                        wire:model="databaseNotificationsEnabled"
                        :label="__('In-app notifications')"
                        :description="__('Store outage and recovery alerts in Monitor for later review.')"
                    />
                </div>
            </flux:fieldset>

            <div class="flex flex-wrap items-center gap-3">
                <flux:button variant="primary" type="submit" data-test="save-notification-preferences">
                    {{ __('Save preferences') }}
                </flux:button>

                <flux:link :href="route('notifications.index')" wire:navigate>
                    {{ __('Open notifications') }}
                </flux:link>
            </div>
        </form>
    </x-pages::settings.layout>
</section>
