<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\User;

class UpdateNotificationPreferences
{
    public function handle(
        User $user,
        bool $emailNotificationsEnabled,
        bool $databaseNotificationsEnabled,
    ): void {
        $user->update([
            'email_notifications_enabled' => $emailNotificationsEnabled,
            'database_notifications_enabled' => $databaseNotificationsEnabled,
        ]);
    }
}
