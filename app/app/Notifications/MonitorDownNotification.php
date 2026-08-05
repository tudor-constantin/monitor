<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Incident;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MonitorDownNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
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
            ->error()
            ->subject('Website down: '.$monitor->name)
            ->greeting('A monitor is down')
            ->line($monitor->name.' has failed two consecutive checks.')
            ->line('URL: '.$monitor->url)
            ->line('Cause: '.($this->incident->cause ?? 'Health check failed.'))
            ->action('View website', route('monitors.show', $monitor))
            ->line('Monitor will continue checking this endpoint at its configured interval.');
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
            'status' => 'down',
            'cause' => $this->incident->cause,
            'started_at' => $this->incident->started_at->toISOString(),
        ];
    }

    public function databaseType(object $notifiable): string
    {
        return 'monitor-down';
    }
}
