<?php

namespace Lyre\Billing\Services;

use Illuminate\Database\Eloquent\Model;
use Lyre\Billing\Support\BillingSupport;

class SubscriptionLifecycleService
{
    public function approveByProviderId(string $providerId, mixed $invoice = null, ?string $provider = null): mixed
    {
        return $this->resolver($provider)->approveByProviderId($providerId, $invoice);
    }

    public function suspendByProviderId(string $providerId, ?string $provider = null): mixed
    {
        return $this->resolver($provider)->suspendByProviderId($providerId);
    }

    public function paymentFailedByProviderId(string $providerId, mixed $invoice = null, ?string $provider = null): mixed
    {
        return $this->resolver($provider)->paymentFailedByProviderId($providerId, $invoice);
    }

    public function expire(mixed $subscription, ?string $provider = null): mixed
    {
        return $this->resolver($provider ?: BillingSupport::subscriptionProvider($subscription))->expire($subscription);
    }

    public function markRenewalDue(mixed $subscription, ?string $provider = null): mixed
    {
        return $this->resolver($provider ?: BillingSupport::subscriptionProvider($subscription))->markRenewalDue($subscription);
    }

    public function revokeRenewal(Model $subscription): mixed
    {
        $provider = BillingSupport::subscriptionProvider($subscription);

        if (! $provider) {
            $subscription->update(['auto_renew' => false]);

            return $subscription->fresh();
        }

        return $this->resolver($provider)->revokeRenewal($subscription);
    }

    public function restoreRenewal(Model $subscription): mixed
    {
        $provider = BillingSupport::subscriptionProvider($subscription);

        if (! $provider) {
            $subscription->update(['auto_renew' => true]);

            return $subscription->fresh();
        }

        return $this->resolver($provider)->restoreRenewal($subscription);
    }

    protected function resolver(?string $provider): object
    {
        $resolvedProvider = strtolower($provider ?: (string) config('billing.subscriptions.provider', 'paypal'));
        $serviceClass = config("billing.providers.{$resolvedProvider}.subscription_lifecycle_service");

        if (! $serviceClass || ! class_exists($serviceClass)) {
            throw new \InvalidArgumentException("Unsupported subscription provider [{$resolvedProvider}]");
        }

        return app($serviceClass);
    }
}
