<?php

namespace Lyre\Billing\Services\Stripe;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Lyre\Billing\Models\Invoice;
use Lyre\Billing\Support\BillingSupport;

class PlanSubscriptionService
{
    public function startCheckout(Model $plan, Model $subscription, Invoice $invoice): array
    {
        Log::info('billing.stripe_checkout.start', [
            'plan_id' => $plan->getKey(),
            'subscription_id' => $subscription->getKey(),
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'existing_customer_id' => StripeModelBridge::getCustomerId($subscription),
        ]);

        $stripe = Client::make();
        $this->ensureRecurringPrice($plan, $stripe);
        Log::info('billing.stripe_checkout.recurring_price_ready', [
            'plan_id' => $plan->getKey(),
            'stripe_product_id' => StripeModelBridge::getPlanProductId($plan),
            'stripe_price_id' => StripeModelBridge::getPlanPriceId($plan),
        ]);

        $customerId = StripeModelBridge::getCustomerId($subscription);
        if (! $customerId) {
            Log::info('billing.stripe_checkout.customer_creating', [
                'subscription_id' => $subscription->getKey(),
                'user_id' => $subscription->user_id,
                'email' => BillingSupport::subscriptionEmail($subscription),
            ]);
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
            $subscription->save();
            Log::info('billing.stripe_checkout.customer_created', [
                'subscription_id' => $subscription->getKey(),
                'customer_id' => $customerId,
            ]);
        }

        $successUrl = (string) config('billing.providers.stripe.return_url');
        $cancelUrl = (string) config('billing.providers.stripe.cancel_url', $successUrl);
        $successUrlWithSession = $this->appendQueryParameter($successUrl, 'session_id', '{CHECKOUT_SESSION_ID}');
        Log::info('billing.stripe_checkout.urls_resolved', [
            'subscription_id' => $subscription->getKey(),
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'success_url_with_session' => $successUrlWithSession,
        ]);

        $session = $stripe->checkout->sessions->create([
            'mode' => 'subscription',
            'customer' => $customerId,
            'success_url' => $successUrlWithSession,
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
        Log::info('billing.stripe_checkout.session_created', [
            'subscription_id' => $subscription->getKey(),
            'session_id' => $session->id,
            'session_url' => $session->url,
        ]);

        StripeModelBridge::setCheckoutSessionId($subscription, $session->id);
        StripeModelBridge::setCheckoutUrl($subscription, $session->url);
        $subscription->save();
        Log::info('billing.stripe_checkout.subscription_saved', [
            'subscription_id' => $subscription->getKey(),
            'session_id' => $session->id,
        ]);

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
            Log::info('billing.stripe_checkout.product_creating', [
                'plan_id' => $plan->getKey(),
            ]);
            $product = $stripe->products->create([
                'name' => BillingSupport::planDisplayName($plan),
                'description' => BillingSupport::planDisplayDescription($plan),
                'metadata' => [
                    'subscription_plan_id' => (string) $plan->id,
                ],
            ]);

            StripeModelBridge::setPlanProductId($plan, $product->id);
            Log::info('billing.stripe_checkout.product_created', [
                'plan_id' => $plan->getKey(),
                'product_id' => $product->id,
            ]);
        }

        if (! StripeModelBridge::getPlanPriceId($plan)) {
            $recurring = $this->recurringConfig((string) $plan->billing_cycle);
            Log::info('billing.stripe_checkout.price_creating', [
                'plan_id' => $plan->getKey(),
                'product_id' => StripeModelBridge::getPlanProductId($plan),
                'recurring' => $recurring,
                'price' => $plan->price,
                'currency' => strtolower((string) ($plan->currency ?: 'usd')),
            ]);
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
            Log::info('billing.stripe_checkout.price_created', [
                'plan_id' => $plan->getKey(),
                'price_id' => $price->id,
            ]);
        }

        $plan->save();
        Log::info('billing.stripe_checkout.plan_saved', [
            'plan_id' => $plan->getKey(),
            'product_id' => StripeModelBridge::getPlanProductId($plan),
            'price_id' => StripeModelBridge::getPlanPriceId($plan),
        ]);
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

    protected function appendQueryParameter(string $url, string $key, string $value): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return rtrim($url, '/') . "{$separator}{$key}={$value}";
    }
}
