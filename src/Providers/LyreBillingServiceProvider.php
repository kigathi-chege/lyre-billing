<?php

namespace Lyre\Billing\Providers;

use Illuminate\Support\ServiceProvider;

class LyreBillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        register_repositories($this->app, 'Lyre\\Billing\\Repositories', 'Lyre\\Billing\\Contracts');

        // Register billable classes early in register() phase
        // This ensures they're available before boot()
        // $this->registerBillableProxies();
    }

    public function boot(): void
    {
        register_global_observers("Lyre\\Billing\\Models");

        $this->publishesMigrations([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ]);

        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
    }

    protected function registerBillableProxies(): void
    {
        try {
            // Use a deferred callback to ensure all classes are loaded
            $this->app->booted(function () {
                $billableMethods = get_billable_methods();

                logger()->info("🔷 Found " . count($billableMethods) . " billable methods to register", [
                    'methods' => $billableMethods,
                ]);

                foreach ($billableMethods as $method) {
                    $class = $method['class'];

                    try {
                        if (!class_exists($class)) {
                            logger()->warning("🔷 Class does not exist: {$class}");
                            continue;
                        }

                        // Use singleton binding to ensure proxy is used
                        $this->app->singleton($class, function ($app) use ($class) {
                            logger()->info("🔷 Creating instance of: {$class}");
                            $instance = new $class();
                            logger()->info("🔷 Wrapping with proxy: {$class}");
                            return new \Lyre\Billing\Support\BillableProxy($instance);
                        });
                    } catch (\Throwable $e) {
                        logger()->error("🔷 Failed to register billable class: {$class}", [
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);
                    }
                }
            });
        } catch (\Throwable $e) {
            logger()->error("🔷 Failed to register billable proxies", [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
