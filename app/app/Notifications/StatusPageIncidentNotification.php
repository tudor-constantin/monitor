<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Incident;
use App\Models\StatusPageSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Number;

class StatusPageIncidentNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        public Incident $incident,
        public StatusPageSubscription $subscription,
        public bool $recovered,
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
        $this->incident->loadMissing('monitor');
        $this->subscription->loadMissing('statusPage');

        $monitor = $this->incident->monitor;
        $statusPage = $this->subscription->statusPage;
        $unsubscribeUrl = URL::signedRoute('status-page-subscriptions.unsubscribe', [
            'subscription' => $this->subscription,
        ]);

        $message = (new MailMessage)
            ->subject(($this->recovered ? 'Recovered: ' : 'Outage: ').$monitor->name)
            ->greeting($this->recovered ? 'Service recovered' : 'Service disruption detected')
            ->line(
                $this->recovered
                    ? $monitor->name.' is responding successfully again.'
                    : $monitor->name.' is currently unavailable.',
            );

        if ($this->recovered) {
            $message->line(
                'Recorded downtime: '.Number::format($this->incident->duration_seconds ?? 0).' seconds.',
            );
        }

        return $message
            ->action('View system status', route('status-pages.public', $statusPage))
            ->line('You are receiving this message because you subscribed to '.$statusPage->name.'.')
            ->line(new HtmlString(
                '<a href="'.e($unsubscribeUrl).'">Unsubscribe from these updates</a>',
            ));
    }
}
