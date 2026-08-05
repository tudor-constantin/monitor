<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\DnsResolver;
use App\Services\Monitoring\SystemDnsResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(DnsResolver::class, SystemDnsResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // Reading a column that was never selected returns null, which is how a
        // partially loaded Monitor once turned a 60 second check budget into a
        // 15 second queue timeout without anything failing. Outside production
        // that now raises, so the mistake surfaces in tests instead of quietly
        // dropping monitoring data. Production keeps the lenient behaviour: a
        // wrong value beats a fatal on a live check.
        Model::preventAccessingMissingAttributes(
            ! app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
