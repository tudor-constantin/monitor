<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // The parent registers `Gate::check('viewHorizon') || app()->environment('local')`,
        // which leaves the dashboard — including retrying and deleting jobs —
        // open to anyone who can reach the app in local. Re-register without
        // the environment escape hatch so the allow-list is the only way in,
        // in every environment.
        Horizon::auth(fn ($request): bool => Gate::check('viewHorizon', [$request->user()]));

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null): bool {
            $email = optional($user)->email;
            $allowedEmails = config('horizon.allowed_emails', []);

            return is_string($email)
                && is_array($allowedEmails)
                && in_array($email, $allowedEmails, true);
        });
    }
}
