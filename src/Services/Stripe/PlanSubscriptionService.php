<?php

namespace Lyre\Billing\Services\Stripe;

use Illuminate\Database\Eloquent\Model;
use Lyre\Billing\Models\Invoice;
use Lyre\Billing\Support\BillingSupport;

class PlanSubscriptionService
{
    public function startCheckout(Model $plan, Model $subscription, Invoice $invoice): array
    {
        $stripe = Client::make();
        $this->ensureRecurringPrice($plan, $stripe);

        $customerId = StripeModelBridge::getCustomerId($subscription);
        if (! $customerId) {
            $customer = $stripe->customers->create([
                'name' => BillingSupport::subscriptionName($subscription),
                'email' => BillingSupport::subscriptionEmail($subscription),
                'metadata' => [
                    'user_id' => (string) $subscription->user_id,
                    'subscription_id' => (string) $subscription->id,
                ],
            ]);
            $customerId = $customer->id;
            StripeModelBridge::setCustomerId($subscription, $customerId);
        }

        $successUrl = (string) config('billing.providers.stripe.return_url');
        $cancelUrl = (string) config('billing.providers.stripe.cancel_url', $successUrl);

        $session = $stripe->checkout->sessions->create([
            'mode' => 'subscription',
            'customer' => $customerId,
            'success_url' => rtrim($successUrl, '/') . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelUrl,
            'client_reference_id' => $invoice->invoice_number,
            'line_items' => [
                [
                    'price' => StripeModelBridge::getPlanPriceId($plan),
                    'quantity' => 1,
                ],
            ],
            'metadata' => [
                'provider' => 'stripe',
                'invoice_id' => (string) $invoice->id,
                'invoice_number' => (string) $invoice->invoice_number,
                'subscription_id' => (string) $subscription->id,
                'subscription_plan_id' => (string) $plan->id,
            ],
            'subscription_data' => [
                'metadata' => [
                    'provider' => 'stripe',
                    'invoice_number' => (string) $invoice->invoice_number,
                    'subscription_id' => (string) $subscription->id,
                    'subscription_plan_id' => (string) $plan->id,
                ],
                ...($plan->trial_days > 0 ? ['trial_period_days' => (int) $plan->trial_days] : []),
            ],
        ]);

        StripeModelBridge::setCheckoutSessionId($subscription, $session->id);
        StripeModelBridge::setCheckoutUrl($subscription, $session->url);
        $subscription->save();

        return [
            [
                'rel' => 'approve',
                'href' => $session->url,
                'method' => 'GET',
            ],
        ];
    }

    protected function ensureRecurringPrice(Model $plan, \Stripe\StripeClient $stripe): void
    {
        if (! StripeModelBridge::getPlanProductId($plan)) {
            $product = $stripe->products->create([
                'name' => BillingSupport::planDisplayName($plan),
                'description' => BillingSupport::planDisplayDescription($plan),
                'metadata' => [
                    'subscription_plan_id' => (string) $plan->id,
                ],
            ]);

            StripeModelBridge::setPlanProductId($plan, $product->id);
        }

        if (! StripeModelBridge::getPlanPriceId($plan)) {
            $recurring = $this->recurringConfig((string) $plan->billing_cycle);
            $price = $stripe->prices->create([
                'currency' => strtolower((string) ($plan->currency ?: 'usd')),
                'unit_amount' => $this->toMinorUnits((float) $plan->price),
                'product' => StripeModelBridge::getPlanProductId($plan),
                'recurring' => $recurring,
                'metadata' => [
                    'subscription_plan_id' => (string) $plan->id,
                ],
            ]);

            StripeModelBridge::setPlanPriceId($plan, $price->id);
        }

        $plan->save();
    }

    protected function toMinorUnits(float $amount): int
    {
        return (int) round($amount * 100);
    }

    protected function recurringConfig(string $billingCycle): array
    {
        return match ($billingCycle) {
            'per_day' => ['interval' => 'day', 'interval_count' => 1],
            'per_week' => ['interval' => 'week', 'interval_count' => 1],
            'quarterly' => ['interval' => 'month', 'interval_count' => 3],
            'semi_annually' => ['interval' => 'month', 'interval_count' => 6],
            'annually' => ['interval' => 'year', 'interval_count' => 1],
            'monthly' => ['interval' => 'month', 'interval_count' => 1],
            default => ['interval' => 'month', 'interval_count' => 1],
        };
    }
}
