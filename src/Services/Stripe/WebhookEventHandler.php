<?php

namespace Lyre\Billing\Services\Stripe;

use Illuminate\Http\Request;
use Lyre\Billing\Models\Invoice;
use Lyre\Billing\Services\SubscriptionLifecycleService;

class WebhookEventHandler
{
    public function handle(Request $request): void
    {
        $secret = Client::webhookSecret();
        $payload = $request->getContent();
        $signature = (string) $request->header('Stripe-Signature', '');

        if (! $secret) {
            throw new \RuntimeException('Stripe webhook secret is not configured.');
        }

        if (! class_exists(\Stripe\Webhook::class)) {
            throw new \RuntimeException('stripe/stripe-php is required. Install it in lyre/billing.');
        }

        $event = \Stripe\Webhook::constructEvent($payload, $signature, $secret);
        $eventType = (string) data_get($event, 'type', '');
        $object = data_get($event, 'data.object');

        match ($eventType) {
            'checkout.session.completed' => $this->checkoutSessionCompleted($object),
            'customer.subscription.created',
            'customer.subscription.updated' => $this->subscriptionUpserted($object),
            'customer.subscription.deleted',
            'customer.subscription.paused' => $this->subscriptionSuspended($object),
            'invoice.payment_failed' => $this->invoicePaymentFailed($object),
            'invoice.paid' => $this->invoicePaid($object),
            default => null,
        };
    }

    protected function checkoutSessionCompleted($session): void
    {
        $subscriptionId = data_get($session, 'subscription');
        $checkoutSessionId = data_get($session, 'id');
        $invoiceNumber = data_get($session, 'client_reference_id') ?: data_get($session, 'metadata.invoice_number');

        if (! $subscriptionId || ! $checkoutSessionId) {
            return;
        }

        $subscription = StripeModelBridge::findByCheckoutSessionId((string) $checkoutSessionId);
        if (! $subscription) {
            return;
        }

        StripeModelBridge::setSubscriptionId($subscription, (string) $subscriptionId);

        $invoice = $invoiceNumber ? Invoice::where('invoice_number', $invoiceNumber)->first() : null;
        app(SubscriptionLifecycleService::class)->approveByProviderId((string) $subscriptionId, $invoice, 'stripe');
    }

    protected function subscriptionUpserted($stripeSubscription): void
    {
        $providerId = (string) data_get($stripeSubscription, 'id', '');
        if (! $providerId) {
            return;
        }

        $status = (string) data_get($stripeSubscription, 'status', '');
        $subscription = StripeModelBridge::findByStripeSubscriptionId($providerId);
        if (! $subscription) {
            return;
        }

        StripeModelBridge::setCustomerId($subscription, (string) data_get($stripeSubscription, 'customer'));
        StripeModelBridge::setSubscriptionId($subscription, $providerId);

        $normalized = match ($status) {
            'active', 'trialing' => 'active',
            'past_due', 'incomplete', 'incomplete_expired', 'unpaid' => 'paused',
            'canceled' => 'canceled',
            default => $subscription->status,
        };

        $subscription->status = $normalized;
        if (data_get($stripeSubscription, 'current_period_end')) {
            $subscription->end_date = now()->setTimestamp((int) data_get($stripeSubscription, 'current_period_end'));
        }
        $subscription->save();
    }

    protected function subscriptionSuspended($stripeSubscription): void
    {
        $providerId = (string) data_get($stripeSubscription, 'id', '');
        if (! $providerId) {
            return;
        }

        app(SubscriptionLifecycleService::class)->suspendByProviderId($providerId, 'stripe');
    }

    protected function invoicePaymentFailed($invoiceObject): void
    {
        $providerId = (string) data_get($invoiceObject, 'subscription', '');
        if (! $providerId) {
            return;
        }

        $invoiceNumber = data_get($invoiceObject, 'subscription_details.metadata.invoice_number')
            ?: data_get($invoiceObject, 'metadata.invoice_number');
        $invoice = $invoiceNumber ? Invoice::where('invoice_number', $invoiceNumber)->first() : null;

        if ($invoice) {
            $invoice->update([
                'status' => 'failed',
                'amount_paid' => 0,
            ]);
        }

        app(SubscriptionLifecycleService::class)->paymentFailedByProviderId($providerId, $invoice, 'stripe');
    }

    protected function invoicePaid($invoiceObject): void
    {
        $providerId = (string) data_get($invoiceObject, 'subscription', '');
        if (! $providerId) {
            return;
        }

        $invoiceNumber = data_get($invoiceObject, 'subscription_details.metadata.invoice_number')
            ?: data_get($invoiceObject, 'metadata.invoice_number');
        $invoice = $invoiceNumber ? Invoice::where('invoice_number', $invoiceNumber)->first() : null;

        if ($invoice) {
            $amountPaid = ((int) data_get($invoiceObject, 'amount_paid', 0)) / 100;
            $invoice->update([
                'status' => 'paid',
                'amount_paid' => $amountPaid,
            ]);
        }

        app(SubscriptionLifecycleService::class)->approveByProviderId($providerId, $invoice, 'stripe');
    }
}
