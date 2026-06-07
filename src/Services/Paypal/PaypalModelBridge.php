<?php

namespace Lyre\Billing\Services\Paypal;

use Illuminate\Database\Eloquent\Model;
use Lyre\Billing\Support\BillingSupport;

class PaypalModelBridge
{
    public static function getPlanProductId(Model $plan): ?string
    {
        return BillingSupport::getProviderValue($plan, 'paypal', 'product_id');
    }

    public static function setPlanProductId(Model $plan, string $paypalProductId): void
    {
        BillingSupport::setProviderValue($plan, 'paypal', 'product_id', $paypalProductId);
    }

    public static function getPlanId(Model $plan): ?string
    {
        return BillingSupport::getProviderValue($plan, 'paypal', 'plan_id');
    }

    public static function setPlanId(Model $plan, string $paypalPlanId): void
    {
        BillingSupport::setProviderValue($plan, 'paypal', 'plan_id', $paypalPlanId);
    }

    public static function getSubscriptionId(Model $subscription): ?string
    {
        return BillingSupport::getProviderValue($subscription, 'paypal', 'subscription_id');
    }

    public static function setSubscriptionId(Model $subscription, string $paypalSubscriptionId): void
    {
        BillingSupport::setProviderValue($subscription, 'paypal', 'subscription_id', $paypalSubscriptionId);
    }

    public static function setApprovalLink(Model $subscription, ?string $approvalLink): void
    {
        BillingSupport::setProviderValue($subscription, 'paypal', 'approval_link', $approvalLink);
    }

    public static function setStartTime(Model $subscription, string $startTime): void
    {
        BillingSupport::setProviderValue($subscription, 'paypal', 'start_time', $startTime);
    }

    public static function renewLink(string $providerSubscriptionId): string
    {
        return config('services.paypal.base_uri') . "/billing/subscriptions/{$providerSubscriptionId}/capture";
    }

    public static function findSubscriptionByProviderId(string $providerId): mixed
    {
        $subscriptionClass = config('billing.models.subscription');

        return $subscriptionClass::query()
            ->where(function ($query) use ($providerId) {
                $query->where('metadata->providers->paypal->subscription_id', $providerId)
                    ->orWhere('metadata->paypal_subscription_id', $providerId);
            })
            ->firstOrFail();
    }
}
