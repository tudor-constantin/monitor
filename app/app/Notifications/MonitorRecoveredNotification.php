<?php

namespace App\Notifications;

use App\Models\Incident;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Number;

class MonitorRecoveredNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(public Incident $incident)
    {
        $this->onConnection('redis');
        $this->afterCommit();
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return [];
        }

        $channels = [];

        if ($notifiable->database_notifications_enabled) {
            $channels[] = 'database';
        }

        if ($notifiable->email_notifications_enabled) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /**
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        return [
            'database' => 'notifications',
            'mail' => 'notifications',
        ];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $monitor = $this->incident->monitor;

        return (new MailMessage)
            ->subject('Website recovered: '.$monitor->name)
            ->greeting('A monitor has recovered')
            ->line($monitor->name.' is responding successfully again.')
            ->line('URL: '.$monitor->url)
            ->line('Downtime: '.Number::format($this->incident->duration_seconds ?? 0).' seconds')
            ->action('View website', route('monitors.show', $monitor))
            ->line('Monitoring will continue at the configured interval.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $monitor = $this->incident->monitor;

        return [
            'incident_id' => $this->incident->id,
            'monitor_id' => $monitor->id,
            'monitor_name' => $monitor->name,
            'monitor_url' => $monitor->url,
            'status' => 'up',
            'duration_seconds' => $this->incident->duration_seconds,
            'resolved_at' => $this->incident->resolved_at?->toISOString(),
        ];
    }

    public function databaseType(object $notifiable): string
    {
        return 'monitor-recovered';
    }
}
