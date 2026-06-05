<?php

namespace Lyre\Billing\Services\Stripe;

use Illuminate\Database\Eloquent\Model;
use Lyre\Billing\Support\BillingSupport;

class StripeModelBridge
{
    public static function getPlanProductId(Model $plan): ?string
    {
        return BillingSupport::getProviderValue($plan, 'stripe', 'product_id');
    }

    public static function setPlanProductId(Model $plan, string $productId): void
    {
        BillingSupport::setProviderValue($plan, 'stripe', 'product_id', $productId);
    }

    public static function clearPlanProductId(Model $plan): void
    {
        BillingSupport::setProviderValue($plan, 'stripe', 'product_id', null);
    }

    public static function getPlanPriceId(Model $plan): ?string
    {
        return BillingSupport::getProviderValue($plan, 'stripe', 'price_id');
    }

    public static function setPlanPriceId(Model $plan, string $priceId): void
    {
        BillingSupport::setProviderValue($plan, 'stripe', 'price_id', $priceId);
    }

    public static function clearPlanPriceId(Model $plan): void
    {
        BillingSupport::setProviderValue($plan, 'stripe', 'price_id', null);
    }

    public static function getCustomerId(Model $subscription): ?string
    {
        return BillingSupport::getProviderValue($subscription, 'stripe', 'customer_id');
    }

    public static function setCustomerId(Model $subscription, string $customerId): void
    {
        BillingSupport::setProviderValue($subscription, 'stripe', 'customer_id', $customerId);
    }

    public static function clearCustomerId(Model $subscription): void
    {
        BillingSupport::setProviderValue($subscription, 'stripe', 'customer_id', null);
    }

    public static function getSubscriptionId(Model $subscription): ?string
    {
        return BillingSupport::getProviderValue($subscription, 'stripe', 'subscription_id');
    }

    public static function setSubscriptionId(Model $subscription, string $subscriptionId): void
    {
        BillingSupport::setProviderValue($subscription, 'stripe', 'subscription_id', $subscriptionId);
    }

    public static function clearSubscriptionId(Model $subscription): void
    {
        BillingSupport::setProviderValue($subscription, 'stripe', 'subscription_id', null);
    }

    public static function setCheckoutSessionId(Model $subscription, string $sessionId): void
    {
        BillingSupport::setProviderValue($subscription, 'stripe', 'checkout_session_id', $sessionId);
    }

    public static function clearCheckoutSessionId(Model $subscription): void
    {
        BillingSupport::setProviderValue($subscription, 'stripe', 'checkout_session_id', null);
    }

    public static function setCheckoutUrl(Model $subscription, ?string $checkoutUrl): void
    {
        BillingSupport::setProviderValue($subscription, 'stripe', 'checkout_url', $checkoutUrl);
    }

    public static function clearCheckoutUrl(Model $subscription): void
    {
        BillingSupport::setProviderValue($subscription, 'stripe', 'checkout_url', null);
    }

    public static function findByStripeSubscriptionId(string $subscriptionId): mixed
    {
        $subscriptionClass = config('billing.models.subscription');

        return $subscriptionClass::query()
            ->where('metadata->providers->stripe->subscription_id', $subscriptionId)
            ->first();
    }

    public static function findByCheckoutSessionId(string $sessionId): mixed
    {
        $subscriptionClass = config('billing.models.subscription');

        return $subscriptionClass::query()
            ->where('metadata->providers->stripe->checkout_session_id', $sessionId)
            ->first();
    }
}
