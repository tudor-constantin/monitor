<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\StatusPageSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class ConfirmStatusPageSubscription extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        public StatusPageSubscription $subscription,
        public string $token,
    ) {
        $this->onConnection('redis');
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        return ['mail' => 'notifications'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $statusPage = $this->subscription->statusPage;
        $confirmationUrl = URL::temporarySignedRoute(
            'status-page-subscriptions.confirm',
            now()->addHour(),
            [
                'subscription' => $this->subscription,
                'token' => $this->token,
            ],
        );

        return (new MailMessage)
            ->subject('Confirm updates from '.$statusPage->name)
            ->greeting('Confirm your subscription')
            ->line('You requested email updates for '.$statusPage->name.'.')
            ->line('Confirm your address to receive outage and recovery notifications.')
            ->action('Confirm subscription', $confirmationUrl)
            ->line('This link expires in 60 minutes. If you did not request this, no action is required.');
    }
}
