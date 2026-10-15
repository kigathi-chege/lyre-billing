<?php

namespace Lyre\Billing\Providers;

use Illuminate\Support\ServiceProvider;
use Lyre\Billing\Console\Commands\DispatchBillingEvents;
use Lyre\Billing\Models\BillingEventAttempt;
use Lyre\Billing\Models\BillingPayment;
use Lyre\Billing\Models\BillingProviderEvent;
use Lyre\Billing\Models\BillingProviderInvoice;
use Lyre\Billing\Models\BillingReconciliationRun;
use Lyre\Billing\Models\BillingStateChange;
use Lyre\Billing\Services\PaymentManager;
use Lyre\Billing\Support\BillableProxy;

class LyreBillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/billing.php', 'billing');

        // Evidence is inspected through dedicated authorized APIs, never generic CRUD.
        $evidenceModels = [BillingProviderEvent::class, BillingEventAttempt::class, BillingProviderInvoice::class, BillingPayment::class, BillingStateChange::class, BillingReconciliationRun::class];
        config(['lyre-filament-admin.discovery.exclude' => array_values(array_unique(array_merge(config('lyre-filament-admin.discovery.exclude', []), $evidenceModels)))]);

        register_repositories($this->app, 'Lyre\\Billing\\Repositories', 'Lyre\\Billing\\Contracts');
        $this->app->singleton(PaymentManager::class);

        // Register billable classes early in register() phase
        // This ensures they're available before boot()
        // $this->registerBillableProxies();
    }

    public function boot(): void
    {
        register_global_observers('Lyre\\Billing\\Models');
        $this->commands([DispatchBillingEvents::class]);

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ]);
        $this->publishes([
            __DIR__.'/../config/billing.php' => config_path('billing.php'),
        ], 'lyre-billing-config');

        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
    }

    protected function registerBillableProxies(): void
    {
        try {
            // Use a deferred callback to ensure all classes are loaded
            $this->app->booted(function () {
                $billableMethods = get_billable_methods();

                logger()->info('🔷 Found '.count($billableMethods).' billable methods to register', [
                    'methods' => $billableMethods,
                ]);

                foreach ($billableMethods as $method) {
                    $class = $method['class'];

                    try {
                        if (! class_exists($class)) {
                            logger()->warning("🔷 Class does not exist: {$class}");

                            continue;
                        }

                        // Use singleton binding to ensure proxy is used
                        $this->app->singleton($class, function ($app) use ($class) {
                            logger()->info("🔷 Creating instance of: {$class}");
                            $instance = new $class;
                            logger()->info("🔷 Wrapping with proxy: {$class}");

                            return new BillableProxy($instance);
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
            logger()->error('🔷 Failed to register billable proxies', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
