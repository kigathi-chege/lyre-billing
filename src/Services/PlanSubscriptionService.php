<?php

namespace Lyre\Billing\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Lyre\Billing\Models\Invoice;
use Lyre\Billing\Models\SubscriptionEntitlement;
use Lyre\Billing\Support\BillingSupport;
use Lyre\Exceptions\CommonException;

class PlanSubscriptionService
{
    public function subscribeToPlan(Model $plan, ?Authenticatable $user = null, ?string $provider = null): array
    {
        $user ??= auth()->user();

        if (! $user) {
            throw CommonException::fromMessage('You must be logged in to subscribe to this plan.', 403);
        }

        $provider = strtolower((string) ($provider ?: config('billing.subscriptions.provider', 'paypal')));

        $subscriptionClass = method_exists($plan, 'subscriptions')
            ? get_class($plan->subscriptions()->getModel())
            : config('billing.models.subscription');

        /** @var \Illuminate\Database\Eloquent\Model $subscription */
        $subscription = $subscriptionClass::firstOrCreate(
            [
                'user_id' => $user->getAuthIdentifier(),
                'subscription_plan_id' => $plan->getKey(),
                'status' => 'pending',
            ],
            $this->subscriptionDefaults($plan, $user)
        );

        if (! $subscription->wasRecentlyCreated) {
            throw CommonException::fromMessage('You are already subscribed to this plan.');
        }

        BillingSupport::hydrateSubscriptionProfile($subscription, $user);
        $subscription->save();

        $invoice = Invoice::create([
            'amount' => $plan->price,
            'subscription_id' => $subscription->getKey(),
        ]);

        $links = $this->startProviderCheckout($provider, $plan, $subscription, $invoice);
        $this->syncCompatibilityEntitlements($plan, $subscription);

        return [
            'subscription' => $subscription->fresh(),
            'invoice' => $invoice->fresh(),
            'links' => $links,
        ];
    }

    protected function subscriptionDefaults(Model $plan, Authenticatable $user): array
    {
        $defaults = [
            'start_date' => now(),
            'auto_renew' => true,
            'end_date' => $this->resolveEndDate($plan),
        ];

        $subscriptionClass = config('billing.models.subscription');
        $prototype = new $subscriptionClass();

        foreach (['name', 'email'] as $field) {
            if (BillingSupport::hasColumn($prototype, $field) && data_get($user, $field)) {
                $defaults[$field] = data_get($user, $field);
            }
        }

        return $defaults;
    }

    protected function resolveEndDate(Model $plan): ?\Carbon\CarbonInterface
    {
        return match ($plan->billing_cycle) {
            'per_minute' => now()->addMinute(),
            'per_hour' => now()->addHour(),
            'per_day' => now()->addDay(),
            'per_week' => now()->addWeek(),
            'quarterly' => now()->addMonths(3),
            'semi_annually' => now()->addMonths(6),
            'annually' => now()->addYear(),
            default => now()->addMonth(),
        };
    }

    protected function startProviderCheckout(
        string $provider,
        Model $plan,
        Model $subscription,
        Invoice $invoice
    ): array {
        $serviceClass = config("billing.providers.{$provider}.plan_subscription_service");

        if (! $serviceClass || ! class_exists($serviceClass)) {
            throw CommonException::fromMessage("Unsupported subscription provider [{$provider}].", 422);
        }

        return app($serviceClass)->startCheckout($plan, $subscription, $invoice);
    }

    protected function syncCompatibilityEntitlements(Model $plan, Model $subscription): void
    {
        // Compatibility bridge for Aspire-style query payload: ?product=exam,1,2,3
        $productQuery = (string) request()->query('product', '');
        if ($productQuery !== '') {
            $parts = array_values(array_filter(explode(',', $productQuery)));
            $typeToken = strtolower((string) array_shift($parts));
            $ids = collect($parts)->map(fn ($id) => (int) $id)->filter(fn ($id) => $id > 0)->values();
            $modelClass = config("billing.entitlements.type_map.{$typeToken}");

            if ($modelClass && class_exists($modelClass)) {
                foreach ($ids as $id) {
                    SubscriptionEntitlement::firstOrCreate([
                        'subscription_id' => $subscription->getKey(),
                        'entitlable_type' => $modelClass,
                        'entitlable_id' => $id,
                    ], [
                        'source' => 'compat_legacy_product',
                    ]);
                }
            }

            return;
        }

        // Plan-level entitlement presets for package-native usage.
        $presetEntitlements = data_get($plan, 'metadata.entitlements', []);
        if (! is_array($presetEntitlements)) {
            return;
        }

        foreach ($presetEntitlements as $entitlement) {
            $type = data_get($entitlement, 'type');
            $id = (int) data_get($entitlement, 'id');
            if (! $type || $id <= 0 || ! class_exists($type)) {
                continue;
            }

            SubscriptionEntitlement::firstOrCreate([
                'subscription_id' => $subscription->getKey(),
                'entitlable_type' => $type,
                'entitlable_id' => $id,
            ], [
                'source' => 'plan',
            ]);
        }
    }
}
