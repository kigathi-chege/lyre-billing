<?php

namespace Lyre\Billing\Services\Stripe;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Lyre\Billing\Models\Invoice;
use Lyre\Billing\Support\BillingSupport;
use Stripe\Exception\InvalidRequestException;

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

        $customerId = $this->ensureCustomer($subscription, $stripe);

        $successUrl = (string) config('billing.providers.stripe.return_url');
        $cancelUrl = (string) config('billing.providers.stripe.cancel_url', $successUrl);
        $successUrlWithSession = $this->appendQueryParameter($successUrl, 'session_id', '{CHECKOUT_SESSION_ID}');
        Log::info('billing.stripe_checkout.urls_resolved', [
            'subscription_id' => $subscription->getKey(),
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'success_url_with_session' => $successUrlWithSession,
        ]);

        $session = $this->createCheckoutSession(
            $stripe,
            $plan,
            $subscription,
            $invoice,
            $customerId,
            $successUrlWithSession,
            $cancelUrl
        );
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
        $productId = StripeModelBridge::getPlanProductId($plan);
        if ($productId) {
            try {
                $stripe->products->retrieve($productId, []);
            } catch (InvalidRequestException $exception) {
                if (! $this->isMissingStripeResource($exception, 'product')) {
                    throw $exception;
                }

                Log::warning('billing.stripe_checkout.product_missing_recreating', [
                    'plan_id' => $plan->getKey(),
                    'product_id' => $productId,
                    'message' => $exception->getMessage(),
                ]);
                StripeModelBridge::clearPlanProductId($plan);
                StripeModelBridge::clearPlanPriceId($plan);
            }
        }

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

        $priceId = StripeModelBridge::getPlanPriceId($plan);
        if ($priceId) {
            try {
                $price = $stripe->prices->retrieve($priceId, []);
                if ((string) $price->product !== (string) StripeModelBridge::getPlanProductId($plan)) {
                    Log::warning('billing.stripe_checkout.price_product_mismatch', [
                        'plan_id' => $plan->getKey(),
                        'price_id' => $priceId,
                        'expected_product_id' => StripeModelBridge::getPlanProductId($plan),
                        'actual_product_id' => (string) $price->product,
                    ]);
                    StripeModelBridge::clearPlanPriceId($plan);
                }
            } catch (InvalidRequestException $exception) {
                if (! $this->isMissingStripeResource($exception, 'price')) {
                    throw $exception;
                }

                Log::warning('billing.stripe_checkout.price_missing_recreating', [
                    'plan_id' => $plan->getKey(),
                    'price_id' => $priceId,
                    'message' => $exception->getMessage(),
                ]);
                StripeModelBridge::clearPlanPriceId($plan);
            }
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

    protected function ensureCustomer(Model $subscription, \Stripe\StripeClient $stripe): string
    {
        $customerId = StripeModelBridge::getCustomerId($subscription);

        if ($customerId) {
            try {
                $stripe->customers->retrieve($customerId, []);
                return $customerId;
            } catch (InvalidRequestException $exception) {
                if (! $this->isMissingStripeResource($exception, 'customer')) {
                    throw $exception;
                }

                Log::warning('billing.stripe_checkout.customer_missing_recreating', [
                    'subscription_id' => $subscription->getKey(),
                    'customer_id' => $customerId,
                    'message' => $exception->getMessage(),
                ]);

                StripeModelBridge::clearCustomerId($subscription);
                StripeModelBridge::clearSubscriptionId($subscription);
                StripeModelBridge::clearCheckoutSessionId($subscription);
                StripeModelBridge::clearCheckoutUrl($subscription);
                $subscription->save();
            }
        }

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

        StripeModelBridge::setCustomerId($subscription, $customer->id);
        $subscription->save();

        Log::info('billing.stripe_checkout.customer_created', [
            'subscription_id' => $subscription->getKey(),
            'customer_id' => $customer->id,
        ]);

        return $customer->id;
    }

    protected function createCheckoutSession(
        \Stripe\StripeClient $stripe,
        Model $plan,
        Model $subscription,
        Invoice $invoice,
        string $customerId,
        string $successUrlWithSession,
        string $cancelUrl,
        bool $allowRecovery = true
    ): \Stripe\Checkout\Session {
        try {
            return $stripe->checkout->sessions->create([
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
        } catch (InvalidRequestException $exception) {
            if (! $allowRecovery) {
                throw $exception;
            }

            $recovered = $this->recoverFromSessionCreationFailure($exception, $stripe, $plan, $subscription);
            if (! $recovered) {
                throw $exception;
            }

            $this->ensureRecurringPrice($plan, $stripe);
            $customerId = $this->ensureCustomer($subscription, $stripe);

            return $this->createCheckoutSession(
                $stripe,
                $plan,
                $subscription,
                $invoice,
                $customerId,
                $successUrlWithSession,
                $cancelUrl,
                false
            );
        }
    }

    protected function recoverFromSessionCreationFailure(
        InvalidRequestException $exception,
        \Stripe\StripeClient $stripe,
        Model $plan,
        Model $subscription
    ): bool {
        if ($this->isMissingStripeResource($exception, 'customer')) {
            Log::warning('billing.stripe_checkout.session_retrying_with_new_customer', [
                'subscription_id' => $subscription->getKey(),
                'customer_id' => StripeModelBridge::getCustomerId($subscription),
                'message' => $exception->getMessage(),
            ]);
            StripeModelBridge::clearCustomerId($subscription);
            StripeModelBridge::clearSubscriptionId($subscription);
            StripeModelBridge::clearCheckoutSessionId($subscription);
            StripeModelBridge::clearCheckoutUrl($subscription);
            $subscription->save();

            return true;
        }

        if ($this->isMissingStripeResource($exception, 'price')) {
            Log::warning('billing.stripe_checkout.session_retrying_with_new_price', [
                'plan_id' => $plan->getKey(),
                'price_id' => StripeModelBridge::getPlanPriceId($plan),
                'message' => $exception->getMessage(),
            ]);
            StripeModelBridge::clearPlanPriceId($plan);
            $plan->save();

            return true;
        }

        if ($this->isMissingStripeResource($exception, 'product')) {
            Log::warning('billing.stripe_checkout.session_retrying_with_new_product', [
                'plan_id' => $plan->getKey(),
                'product_id' => StripeModelBridge::getPlanProductId($plan),
                'message' => $exception->getMessage(),
            ]);
            StripeModelBridge::clearPlanProductId($plan);
            StripeModelBridge::clearPlanPriceId($plan);
            $plan->save();

            return true;
        }

        return false;
    }

    protected function isMissingStripeResource(InvalidRequestException $exception, string $resource): bool
    {
        return str_contains(strtolower($exception->getMessage()), "no such {$resource}");
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
