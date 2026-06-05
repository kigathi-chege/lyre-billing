<?php

namespace Lyre\Billing\Services\Stripe;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Lyre\Billing\Models\Invoice;
use Lyre\Billing\Services\SubscriptionLifecycleService;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;

class WebhookEventHandler
{
    public function handle(Request $request): void
    {
        $payload = $request->getContent();
        $signature = (string) $request->header('Stripe-Signature', '');
        $secrets = Client::webhookSecrets();

        if ($signature === '') {
            throw new \RuntimeException('Stripe webhook signature header is missing.');
        }

        if ($secrets === []) {
            throw new \RuntimeException('Stripe webhook secret is not configured.');
        }

        if (! class_exists(\Stripe\Webhook::class)) {
            throw new \RuntimeException('stripe/stripe-php is required. Install it in lyre/billing.');
        }

        $resolvedEvent = $this->resolveEvent($payload, $signature, $secrets);
        $eventType = $resolvedEvent['event_type'];
        $object = $resolvedEvent['object'];

        Log::info('billing.stripe_webhook.received', [
            'delivery_mode' => $resolvedEvent['delivery_mode'],
            'event_id' => $resolvedEvent['event_id'],
            'event_type' => $eventType,
            'raw_event_type' => $resolvedEvent['raw_event_type'],
            'object_type' => is_object($object) ? data_get($object, 'object') : gettype($object),
        ]);

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

    protected function resolveEvent(string $payload, string $signature, array $secrets): array
    {
        $snapshotEvent = $this->parseSnapshotEvent($payload, $signature, $secrets);
        if ($snapshotEvent) {
            return $snapshotEvent;
        }

        $thinEvent = $this->parseThinEvent($payload, $signature, $secrets);
        if ($thinEvent) {
            return $thinEvent;
        }

        throw new \RuntimeException('Stripe webhook signature could not be verified for either snapshot or thin payloads.');
    }

    protected function parseSnapshotEvent(string $payload, string $signature, array $secrets): ?array
    {
        foreach ($secrets as $secret) {
            try {
                $event = \Stripe\Webhook::constructEvent($payload, $signature, $secret);

                return [
                    'delivery_mode' => 'snapshot',
                    'event_id' => (string) data_get($event, 'id', ''),
                    'raw_event_type' => (string) data_get($event, 'type', ''),
                    'event_type' => (string) data_get($event, 'type', ''),
                    'object' => data_get($event, 'data.object'),
                ];
            } catch (SignatureVerificationException) {
                continue;
            }
        }

        return null;
    }

    protected function parseThinEvent(string $payload, string $signature, array $secrets): ?array
    {
        $client = Client::make(Client::thinWebhookApiVersion());

        foreach ($secrets as $secret) {
            try {
                $event = $client->parseThinEvent($payload, $signature, $secret);
                $rawEventType = (string) data_get($event, 'type', '');

                return [
                    'delivery_mode' => 'thin',
                    'event_id' => (string) data_get($event, 'id', ''),
                    'raw_event_type' => $rawEventType,
                    'event_type' => $this->normalizeThinEventType($rawEventType),
                    'object' => $this->resolveThinEventObject($event, $client),
                ];
            } catch (SignatureVerificationException) {
                continue;
            }
        }

        return null;
    }

    protected function normalizeThinEventType(string $eventType): string
    {
        if (str_starts_with($eventType, 'v1.')) {
            return substr($eventType, 3);
        }

        return $eventType;
    }

    protected function resolveThinEventObject(\Stripe\ThinEvent $event, \Stripe\StripeClient $client): mixed
    {
        $opts = [];
        $context = (string) data_get($event, 'context', '');
        if ($context !== '') {
            $opts['stripe_context'] = $context;
        }

        try {
            $fullEvent = $client->v2->core->events->retrieve((string) $event->id, [], $opts);
            $object = data_get($fullEvent, 'data.object');
            if ($object) {
                return $object;
            }

            $relatedUrl = (string) data_get($fullEvent, 'related_object.url', '');
            if ($relatedUrl !== '') {
                return $this->fetchStripeObjectByUrl($client, $relatedUrl, $opts);
            }
        } catch (ApiErrorException $exception) {
            Log::warning('billing.stripe_webhook.thin_event_retrieve_failed', [
                'event_id' => (string) data_get($event, 'id', ''),
                'event_type' => (string) data_get($event, 'type', ''),
                'message' => $exception->getMessage(),
            ]);
        }

        $relatedUrl = (string) data_get($event, 'related_object.url', '');
        if ($relatedUrl !== '') {
            return $this->fetchStripeObjectByUrl($client, $relatedUrl, $opts);
        }

        return null;
    }

    protected function fetchStripeObjectByUrl(\Stripe\StripeClient $client, string $url, array $opts): mixed
    {
        $path = parse_url($url, PHP_URL_PATH) ?: $url;

        if (! is_string($path) || $path === '') {
            return null;
        }

        try {
            return $client->request('get', $path, [], $opts);
        } catch (ApiErrorException $exception) {
            Log::warning('billing.stripe_webhook.related_object_fetch_failed', [
                'path' => $path,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
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
            Log::warning('billing.stripe_webhook.checkout_session_missing_local_subscription', [
                'checkout_session_id' => $checkoutSessionId,
                'provider_subscription_id' => $subscriptionId,
                'invoice_number' => $invoiceNumber,
            ]);
            return;
        }

        Log::info('billing.stripe_webhook.checkout_session_resolved', [
            'checkout_session_id' => $checkoutSessionId,
            'local_subscription_id' => $subscription->getKey(),
            'provider_subscription_id' => $subscriptionId,
            'invoice_number' => $invoiceNumber,
        ]);

        StripeModelBridge::setCustomerId($subscription, (string) data_get($session, 'customer'));
        StripeModelBridge::setSubscriptionId($subscription, (string) $subscriptionId);
        $subscription->save();

        Log::info('billing.stripe_webhook.checkout_session_saved', [
            'checkout_session_id' => $checkoutSessionId,
            'local_subscription_id' => $subscription->getKey(),
            'provider_subscription_id' => $subscriptionId,
        ]);

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
