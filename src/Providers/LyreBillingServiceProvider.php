<?php

namespace Lyre\Billing\Providers;

use Illuminate\Support\ServiceProvider;

class LyreBillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        register_repositories($this->app, 'Lyre\\Billing\\Repositories', 'Lyre\\Billing\\Contracts');
    }

    public function boot(): void
    {
        register_global_observers("Lyre\\Billing\\Models");

        $this->publishesMigrations([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ]);

        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
    }
}
