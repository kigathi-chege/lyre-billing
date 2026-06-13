<?php

namespace Lyre\Billing\Services\Stripe;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Lyre\Billing\Events\SubscriptionInvoiceFinalizationFailed;
use Lyre\Billing\Events\SubscriptionPaymentFailed;
use Lyre\Billing\Events\SubscriptionPaymentActionRequired;
use Lyre\Billing\Events\SubscriptionPendingUpdateApplied;
use Lyre\Billing\Events\SubscriptionPendingUpdateExpired;
use Lyre\Billing\Events\SubscriptionProviderCancelled;
use Lyre\Billing\Events\SubscriptionTrialWillEnd;
use Lyre\Billing\Models\Invoice;
use Lyre\Billing\Models\PaymentMethod;
use Lyre\Billing\Models\Transaction;
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
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded' => $this->checkoutSessionCompleted($object),
            'checkout.session.async_payment_failed' => $this->checkoutSessionAsyncPaymentFailed($object),
            'checkout.session.expired' => $this->checkoutSessionExpired($object),
            'customer.subscription.created',
            'customer.subscription.updated' => $this->subscriptionUpserted($object),
            'customer.subscription.deleted',
            'customer.subscription.paused' => $this->subscriptionSuspended($object),
            'customer.subscription.resumed' => $this->subscriptionResumed($object),
            'customer.subscription.trial_will_end' => $this->subscriptionTrialWillEnd($object),
            'customer.subscription.pending_update_applied' => $this->subscriptionPendingUpdateApplied($object),
            'customer.subscription.pending_update_expired' => $this->subscriptionPendingUpdateExpired($object),
            'invoice.created' => $this->invoiceCreated($object),
            'invoice.finalized' => $this->invoiceFinalized($object),
            'invoice.finalization_failed' => $this->invoiceFinalizationFailed($object),
            'invoice.upcoming' => $this->invoiceUpcoming($object),
            'invoice.payment_action_required' => $this->invoicePaymentActionRequired($object),
            'invoice.payment_failed' => $this->invoicePaymentFailed($object),
            'invoice.paid',
            'invoice.payment_succeeded' => $this->invoicePaid($object),
            'invoice_payment.paid' => $this->invoicePaymentPaid($object),
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
        return str_starts_with($eventType, 'v1.')
            ? substr($eventType, 3)
            : $eventType;
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
        $providerSubscriptionId = (string) data_get($session, 'subscription', '');
        $checkoutSessionId = (string) data_get($session, 'id', '');
        $invoiceNumber = data_get($session, 'client_reference_id') ?: data_get($session, 'metadata.invoice_number');

        if ($providerSubscriptionId === '' || $checkoutSessionId === '') {
            return;
        }

        $subscription = StripeModelBridge::findByCheckoutSessionId($checkoutSessionId);
        if (! $subscription) {
            Log::warning('billing.stripe_webhook.checkout_session_missing_local_subscription', [
                'checkout_session_id' => $checkoutSessionId,
                'provider_subscription_id' => $providerSubscriptionId,
                'invoice_number' => $invoiceNumber,
            ]);

            return;
        }

        Log::info('billing.stripe_webhook.checkout_session_resolved', [
            'checkout_session_id' => $checkoutSessionId,
            'local_subscription_id' => $subscription->getKey(),
            'provider_subscription_id' => $providerSubscriptionId,
            'invoice_number' => $invoiceNumber,
        ]);

        StripeModelBridge::setCustomerId($subscription, (string) data_get($session, 'customer', ''));
        StripeModelBridge::setSubscriptionId($subscription, $providerSubscriptionId);
        $subscription->save();

        Log::info('billing.stripe_webhook.checkout_session_saved', [
            'checkout_session_id' => $checkoutSessionId,
            'local_subscription_id' => $subscription->getKey(),
            'provider_subscription_id' => $providerSubscriptionId,
        ]);

        $invoice = $invoiceNumber ? Invoice::query()->where('invoice_number', $invoiceNumber)->first() : null;
        $invoice ??= $this->syncLocalInvoiceFromStripeInvoiceId((string) data_get($session, 'invoice', ''), $subscription);

        $this->upsertTransactionTelemetry(
            $subscription,
            $checkoutSessionId,
            [
                'status' => 'completed',
                'raw_callback' => json_encode($session),
                'metadata' => [
                    'provider' => 'stripe',
                    'checkout_session_id' => $checkoutSessionId,
                    'provider_subscription_id' => $providerSubscriptionId,
                    'checkout_completed_at' => now()->toIso8601String(),
                    'webhook_event_type' => 'checkout.session.completed',
                ],
            ],
            $invoice
        );

        app(SubscriptionLifecycleService::class)->approveByProviderId($providerSubscriptionId, $invoice, 'stripe');
    }

    protected function checkoutSessionAsyncPaymentFailed($session): void
    {
        $checkoutSessionId = (string) data_get($session, 'id', '');
        if ($checkoutSessionId === '') {
            return;
        }

        $subscription = StripeModelBridge::findByCheckoutSessionId($checkoutSessionId);
        if (! $subscription) {
            return;
        }

        $invoice = $this->syncLocalInvoiceFromStripeInvoiceId((string) data_get($session, 'invoice', ''), $subscription);

        $this->upsertTransactionTelemetry(
            $subscription,
            $checkoutSessionId,
            [
                'status' => 'failed',
                'raw_callback' => json_encode($session),
                'metadata' => [
                    'provider' => 'stripe',
                    'checkout_session_id' => $checkoutSessionId,
                    'webhook_event_type' => 'checkout.session.async_payment_failed',
                    'webhook_received_at' => now()->toIso8601String(),
                ],
            ],
            $invoice
        );

        event(new SubscriptionPaymentFailed(
            $subscription->fresh(),
            $invoice?->fresh(),
        ));
    }

    protected function checkoutSessionExpired($session): void
    {
        $checkoutSessionId = (string) data_get($session, 'id', '');
        if ($checkoutSessionId === '') {
            return;
        }

        $subscription = StripeModelBridge::findByCheckoutSessionId($checkoutSessionId);
        if (! $subscription) {
            return;
        }

        if ($subscription->status === 'pending') {
            $subscription->status = 'canceled';
            $subscription->save();
        }

        $invoice = $this->syncLocalInvoiceFromStripeInvoiceId((string) data_get($session, 'invoice', ''), $subscription);
        if ($invoice && $invoice->status !== 'paid') {
            $invoice->status = 'failed';
            $invoice->save();
        }

        $this->upsertTransactionTelemetry(
            $subscription,
            $checkoutSessionId,
            [
                'status' => 'cancelled',
                'raw_callback' => json_encode($session),
                'metadata' => [
                    'provider' => 'stripe',
                    'checkout_session_id' => $checkoutSessionId,
                    'webhook_event_type' => 'checkout.session.expired',
                    'webhook_received_at' => now()->toIso8601String(),
                ],
            ],
            $invoice
        );

        event(new SubscriptionProviderCancelled(
            $subscription->fresh(),
            'stripe',
            'expired',
            is_array($session) ? $session : json_decode(json_encode($session), true) ?? []
        ));
    }

    protected function subscriptionUpserted($stripeSubscription): void
    {
        $providerId = (string) data_get($stripeSubscription, 'id', '');
        if ($providerId === '') {
            return;
        }

        $status = (string) data_get($stripeSubscription, 'status', '');
        $subscription = StripeModelBridge::findByStripeSubscriptionId($providerId);
        if (! $subscription) {
            return;
        }

        StripeModelBridge::setCustomerId($subscription, (string) data_get($stripeSubscription, 'customer', ''));
        StripeModelBridge::setSubscriptionId($subscription, $providerId);

        $normalized = match ($status) {
            'active', 'trialing' => 'active',
            'incomplete' => 'pending',
            'past_due', 'incomplete_expired', 'unpaid', 'paused' => 'paused',
            'canceled' => 'canceled',
            default => (string) $subscription->status,
        };

        $normalized = $this->preserveMoreAdvancedSubscriptionStatus(
            $subscription,
            $normalized,
            $status,
            $providerId
        );

        $subscription->status = $normalized;
        if (data_get($stripeSubscription, 'current_period_end')) {
            $subscription->end_date = now()->setTimestamp((int) data_get($stripeSubscription, 'current_period_end'));
        }
        $subscription->save();

        $invoice = $this->syncLocalInvoiceFromSubscriptionPayload($stripeSubscription);

        $this->upsertTransactionTelemetry(
            $subscription,
            $providerId,
            [
                'status' => match ($normalized) {
                    'active' => 'completed',
                    'canceled' => 'cancelled',
                    'paused' => 'failed',
                    default => 'pending',
                },
                'raw_callback' => json_encode($stripeSubscription),
                'metadata' => [
                    'provider' => 'stripe',
                    'provider_subscription_id' => $providerId,
                    'subscription_status' => $status,
                    'subscription_synced_at' => now()->toIso8601String(),
                    'webhook_event_type' => 'customer.subscription.updated',
                ],
            ],
            $invoice
        );
    }

    protected function subscriptionResumed($stripeSubscription): void
    {
        $providerId = (string) data_get($stripeSubscription, 'id', '');
        if ($providerId === '') {
            return;
        }

        $subscription = StripeModelBridge::findByStripeSubscriptionId($providerId);
        if (! $subscription) {
            return;
        }

        StripeModelBridge::setCustomerId($subscription, (string) data_get($stripeSubscription, 'customer', ''));
        StripeModelBridge::setSubscriptionId($subscription, $providerId);
        $subscription->status = 'active';
        if (data_get($stripeSubscription, 'current_period_end')) {
            $subscription->end_date = now()->setTimestamp((int) data_get($stripeSubscription, 'current_period_end'));
        }
        $subscription->save();

        $invoice = $this->syncLocalInvoiceFromSubscriptionPayload($stripeSubscription);
        $this->upsertTransactionTelemetry(
            $subscription,
            $providerId,
            [
                'status' => 'completed',
                'raw_callback' => json_encode($stripeSubscription),
                'metadata' => [
                    'provider' => 'stripe',
                    'provider_subscription_id' => $providerId,
                    'subscription_status' => (string) data_get($stripeSubscription, 'status', 'active'),
                    'webhook_event_type' => 'customer.subscription.resumed',
                    'webhook_received_at' => now()->toIso8601String(),
                ],
            ],
            $invoice
        );
    }

    protected function subscriptionTrialWillEnd($stripeSubscription): void
    {
        $providerId = (string) data_get($stripeSubscription, 'id', '');
        if ($providerId === '') {
            return;
        }

        if (! $subscription = StripeModelBridge::findByStripeSubscriptionId($providerId)) {
            return;
        }

        Log::info('billing.stripe_webhook.subscription_trial_will_end', [
            'subscription_id' => $subscription->id,
            'provider_subscription_id' => $providerId,
            'trial_end' => data_get($stripeSubscription, 'trial_end'),
        ]);

        $trialEndsAt = data_get($stripeSubscription, 'trial_end')
            ? now()->setTimestamp((int) data_get($stripeSubscription, 'trial_end'))
            : null;

        event(new SubscriptionTrialWillEnd(
            $subscription->fresh(),
            $trialEndsAt,
        ));
    }

    protected function subscriptionPendingUpdateApplied($stripeSubscription): void
    {
        $subscription = $this->syncSubscriptionMetadataOnly($stripeSubscription, 'customer.subscription.pending_update_applied');
        if (! $subscription) {
            return;
        }

        event(new SubscriptionPendingUpdateApplied(
            $subscription->fresh(),
            $this->syncLocalInvoiceFromSubscriptionPayload($stripeSubscription)?->fresh(),
        ));
    }

    protected function subscriptionPendingUpdateExpired($stripeSubscription): void
    {
        $subscription = $this->syncSubscriptionMetadataOnly($stripeSubscription, 'customer.subscription.pending_update_expired');
        if (! $subscription) {
            return;
        }

        event(new SubscriptionPendingUpdateExpired(
            $subscription->fresh(),
            $this->syncLocalInvoiceFromSubscriptionPayload($stripeSubscription)?->fresh(),
        ));
    }

    protected function subscriptionSuspended($stripeSubscription): void
    {
        $providerId = (string) data_get($stripeSubscription, 'id', '');
        if ($providerId === '') {
            return;
        }

        if ($subscription = StripeModelBridge::findByStripeSubscriptionId($providerId)) {
            $invoice = $this->syncLocalInvoiceFromSubscriptionPayload($stripeSubscription);
            $this->upsertTransactionTelemetry(
                $subscription,
                $providerId,
                [
                    'status' => 'cancelled',
                    'raw_callback' => json_encode($stripeSubscription),
                    'metadata' => [
                        'provider' => 'stripe',
                        'provider_subscription_id' => $providerId,
                        'subscription_cancelled_at' => now()->toIso8601String(),
                        'webhook_event_type' => 'customer.subscription.deleted',
                    ],
                ],
                $invoice
            );
        }

        app(SubscriptionLifecycleService::class)->suspendByProviderId($providerId, 'stripe');
    }

    protected function invoiceCreated($invoiceObject): void
    {
        $subscription = $this->resolveSubscriptionFromInvoiceObject($invoiceObject);
        if (! $subscription) {
            return;
        }

        $invoice = $this->syncLocalInvoiceFromStripeObject($invoiceObject, 'pending', $subscription);
        if (! $invoice) {
            return;
        }

        $this->upsertTransactionTelemetry(
            $subscription,
            $this->invoiceProviderReference($invoiceObject),
            [
                'status' => 'pending',
                'raw_callback' => json_encode($invoiceObject),
                'metadata' => [
                    'provider' => 'stripe',
                    'provider_subscription_id' => (string) data_get($invoiceObject, 'subscription', ''),
                    'webhook_event_type' => 'invoice.created',
                    'webhook_received_at' => now()->toIso8601String(),
                ],
            ],
            $invoice
        );
    }

    protected function invoiceFinalized($invoiceObject): void
    {
        $subscription = $this->resolveSubscriptionFromInvoiceObject($invoiceObject);
        if (! $subscription) {
            return;
        }

        $invoice = $this->syncLocalInvoiceFromStripeObject($invoiceObject, 'pending', $subscription);
        if (! $invoice) {
            return;
        }

        $this->upsertTransactionTelemetry(
            $subscription,
            $this->invoiceProviderReference($invoiceObject),
            [
                'status' => 'pending',
                'raw_callback' => json_encode($invoiceObject),
                'metadata' => [
                    'provider' => 'stripe',
                    'provider_subscription_id' => (string) data_get($invoiceObject, 'subscription', ''),
                    'webhook_event_type' => 'invoice.finalized',
                    'webhook_received_at' => now()->toIso8601String(),
                ],
            ],
            $invoice
        );
    }

    protected function invoiceFinalizationFailed($invoiceObject): void
    {
        $subscription = $this->resolveSubscriptionFromInvoiceObject($invoiceObject);
        if (! $subscription) {
            return;
        }

        $invoice = $this->syncLocalInvoiceFromStripeObject($invoiceObject, 'failed', $subscription);
        if (! $invoice) {
            return;
        }

        $this->upsertTransactionTelemetry(
            $subscription,
            $this->invoiceProviderReference($invoiceObject),
            [
                'status' => 'failed',
                'raw_callback' => json_encode($invoiceObject),
                'metadata' => [
                    'provider' => 'stripe',
                    'provider_subscription_id' => (string) data_get($invoiceObject, 'subscription', ''),
                    'webhook_event_type' => 'invoice.finalization_failed',
                    'last_finalization_error' => data_get($invoiceObject, 'last_finalization_error'),
                    'webhook_received_at' => now()->toIso8601String(),
                ],
            ],
            $invoice
        );

        event(new SubscriptionInvoiceFinalizationFailed(
            $subscription->fresh(),
            $invoice->fresh(),
            data_get($invoiceObject, 'last_finalization_error'),
        ));
    }

    protected function invoiceUpcoming($invoiceObject): void
    {
        $subscription = $this->resolveSubscriptionFromInvoiceObject($invoiceObject);
        if (! $subscription) {
            return;
        }

        $invoice = $this->syncLocalInvoiceFromStripeObject($invoiceObject, 'pending', $subscription);
        app(SubscriptionLifecycleService::class)->markRenewalDue($subscription, 'stripe');

        $this->upsertTransactionTelemetry(
            $subscription,
            $this->invoiceProviderReference($invoiceObject),
            [
                'status' => 'pending',
                'raw_callback' => json_encode($invoiceObject),
                'metadata' => [
                    'provider' => 'stripe',
                    'provider_subscription_id' => (string) data_get($invoiceObject, 'subscription', ''),
                    'webhook_event_type' => 'invoice.upcoming',
                    'webhook_received_at' => now()->toIso8601String(),
                ],
            ],
            $invoice
        );
    }

    protected function invoicePaymentActionRequired($invoiceObject): void
    {
        $subscription = $this->resolveSubscriptionFromInvoiceObject($invoiceObject);
        if (! $subscription) {
            return;
        }

        $invoice = $this->syncLocalInvoiceFromStripeObject($invoiceObject, 'pending', $subscription);
        if (! $invoice) {
            return;
        }

        $this->upsertTransactionTelemetry(
            $subscription,
            $this->invoiceProviderReference($invoiceObject),
            [
                'status' => 'action_required',
                'raw_callback' => json_encode($invoiceObject),
                'metadata' => [
                    'provider' => 'stripe',
                    'provider_subscription_id' => (string) data_get($invoiceObject, 'subscription', ''),
                    'webhook_event_type' => 'invoice.payment_action_required',
                    'payment_intent_id' => (string) data_get($invoiceObject, 'payment_intent', ''),
                    'webhook_received_at' => now()->toIso8601String(),
                ],
            ],
            $invoice
        );

        event(new SubscriptionPaymentActionRequired($subscription->fresh(), $invoice->fresh()));
    }

    protected function invoicePaymentFailed($invoiceObject): void
    {
        $providerId = (string) data_get($invoiceObject, 'subscription', '');
        if ($providerId === '') {
            return;
        }

        $invoice = null;
        if ($subscription = StripeModelBridge::findByStripeSubscriptionId($providerId)) {
            $invoice = $this->syncLocalInvoiceFromStripeObject($invoiceObject, 'failed', $subscription);
            $this->upsertTransactionTelemetry(
                $subscription,
                $this->invoiceProviderReference($invoiceObject, $providerId),
                [
                    'status' => 'failed',
                    'raw_callback' => json_encode($invoiceObject),
                    'metadata' => [
                        'provider' => 'stripe',
                        'provider_subscription_id' => $providerId,
                        'webhook_event_type' => 'invoice.payment_failed',
                        'webhook_received_at' => now()->toIso8601String(),
                    ],
                ],
                $invoice
            );
        }

        app(SubscriptionLifecycleService::class)->paymentFailedByProviderId($providerId, $invoice, 'stripe');
    }

    protected function invoicePaid($invoiceObject): void
    {
        $providerId = (string) data_get($invoiceObject, 'subscription', '');
        if ($providerId === '') {
            return;
        }

        $invoice = null;
        if ($subscription = StripeModelBridge::findByStripeSubscriptionId($providerId)) {
            $invoice = $this->syncLocalInvoiceFromStripeObject($invoiceObject, 'paid', $subscription);
            $this->upsertTransactionTelemetry(
                $subscription,
                $this->invoiceProviderReference($invoiceObject, $providerId),
                [
                    'status' => 'completed',
                    'raw_callback' => json_encode($invoiceObject),
                    'metadata' => [
                        'provider' => 'stripe',
                        'provider_subscription_id' => $providerId,
                        'webhook_event_type' => 'invoice.paid',
                        'webhook_received_at' => now()->toIso8601String(),
                    ],
                ],
                $invoice
            );
        }

        app(SubscriptionLifecycleService::class)->approveByProviderId($providerId, $invoice, 'stripe');
    }

    protected function invoicePaymentPaid($invoicePaymentObject): void
    {
        $stripeInvoiceId = (string) data_get($invoicePaymentObject, 'invoice', '');
        if ($stripeInvoiceId === '') {
            return;
        }

        $invoice = $this->findLocalInvoiceByStripeInvoiceId($stripeInvoiceId);
        $subscription = $invoice?->subscription;
        if (! $subscription || ! $invoice) {
            return;
        }

        $invoice->status = 'paid';
        $invoice->amount_paid = ((int) data_get($invoicePaymentObject, 'amount_paid', 0)) / 100;

        $invoiceMetadata = is_array($invoice->metadata) ? $invoice->metadata : [];
        data_set($invoiceMetadata, 'providers.stripe.invoice_payment_id', (string) data_get($invoicePaymentObject, 'id', ''));
        data_set($invoiceMetadata, 'providers.stripe.invoice_payment_status', (string) data_get($invoicePaymentObject, 'status', ''));
        data_set($invoiceMetadata, 'providers.stripe.invoice_payment_paid_at', data_get($invoicePaymentObject, 'status_transitions.paid_at'));
        $invoice->metadata = $invoiceMetadata;
        $invoice->save();

        $this->upsertTransactionTelemetry(
            $subscription,
            (string) data_get($invoicePaymentObject, 'id', $stripeInvoiceId),
            [
                'status' => 'completed',
                'raw_callback' => json_encode($invoicePaymentObject),
                'metadata' => [
                    'provider' => 'stripe',
                    'stripe_invoice_id' => $stripeInvoiceId,
                    'invoice_payment_id' => (string) data_get($invoicePaymentObject, 'id', ''),
                    'payment_intent_id' => (string) data_get($invoicePaymentObject, 'payment.payment_intent', ''),
                    'webhook_event_type' => 'invoice_payment.paid',
                    'webhook_received_at' => now()->toIso8601String(),
                ],
            ],
            $invoice
        );
    }

    protected function resolveSubscriptionFromInvoiceObject($invoiceObject): mixed
    {
        $providerId = (string) data_get($invoiceObject, 'subscription', '');
        if ($providerId !== '') {
            $subscription = StripeModelBridge::findByStripeSubscriptionId($providerId);
            if ($subscription) {
                return $subscription;
            }
        }

        $localSubscriptionId = (string) (
            data_get($invoiceObject, 'metadata.subscription_id')
            ?: data_get($invoiceObject, 'subscription_details.metadata.subscription_id')
        );

        if ($localSubscriptionId !== '') {
            $subscriptionClass = config('billing.models.subscription');

            return $subscriptionClass::query()->whereKey($localSubscriptionId)->first();
        }

        return null;
    }

    protected function syncSubscriptionMetadataOnly($stripeSubscription, string $eventType): mixed
    {
        $providerId = (string) data_get($stripeSubscription, 'id', '');
        if ($providerId === '') {
            return null;
        }

        $subscription = StripeModelBridge::findByStripeSubscriptionId($providerId);
        if (! $subscription) {
            return null;
        }

        $this->upsertTransactionTelemetry(
            $subscription,
            $providerId,
            [
                'raw_callback' => json_encode($stripeSubscription),
                'metadata' => [
                    'provider' => 'stripe',
                    'provider_subscription_id' => $providerId,
                    'webhook_event_type' => $eventType,
                    'webhook_received_at' => now()->toIso8601String(),
                ],
            ],
            $this->syncLocalInvoiceFromSubscriptionPayload($stripeSubscription)
        );

        return $subscription;
    }

    protected function syncLocalInvoiceFromSubscriptionPayload($stripeSubscription): ?Invoice
    {
        $latestInvoiceId = data_get($stripeSubscription, 'latest_invoice');
        if (is_string($latestInvoiceId) && $latestInvoiceId !== '') {
            return $this->findLocalInvoiceByStripeInvoiceId($latestInvoiceId);
        }

        $providerId = (string) data_get($stripeSubscription, 'id', '');
        if ($providerId === '') {
            return null;
        }

        if (! $subscription = StripeModelBridge::findByStripeSubscriptionId($providerId)) {
            return null;
        }

        return Invoice::query()
            ->where('subscription_id', $subscription->id)
            ->latest('id')
            ->first();
    }

    protected function syncLocalInvoiceFromStripeInvoiceId(string $stripeInvoiceId, mixed $subscription): ?Invoice
    {
        if ($stripeInvoiceId === '') {
            return null;
        }

        $invoice = $this->findLocalInvoiceByStripeInvoiceId($stripeInvoiceId);
        if ($invoice) {
            return $invoice;
        }

        return Invoice::query()
            ->where('subscription_id', $subscription->id)
            ->latest('id')
            ->first();
    }

    protected function syncLocalInvoiceFromStripeObject($invoiceObject, string $fallbackStatus, mixed $subscription): ?Invoice
    {
        $stripeInvoiceId = (string) data_get($invoiceObject, 'id', '');
        $billingReason = (string) data_get($invoiceObject, 'billing_reason', '');
        $invoiceNumber = (string) (
            data_get($invoiceObject, 'subscription_details.metadata.invoice_number')
            ?: data_get($invoiceObject, 'metadata.invoice_number')
            ?: ''
        );

        $invoice = $stripeInvoiceId !== ''
            ? $this->findLocalInvoiceByStripeInvoiceId($stripeInvoiceId)
            : null;

        if (! $invoice && $invoiceNumber !== '') {
            $candidate = Invoice::query()->where('invoice_number', $invoiceNumber)->first();
            if ($candidate) {
                $candidateStripeInvoiceId = (string) data_get($candidate->metadata, 'providers.stripe.invoice_id', '');
                $shouldReuseCandidate = $candidateStripeInvoiceId === $stripeInvoiceId
                    || $candidateStripeInvoiceId === ''
                    || $billingReason === 'subscription_create'
                    || in_array((string) $candidate->status, ['pending', 'failed'], true);

                if ($shouldReuseCandidate) {
                    $invoice = $candidate;
                }
            }
        }

        if (! $invoice) {
            $latestInvoice = Invoice::query()
                ->where('subscription_id', $subscription->id)
                ->latest('id')
                ->first();

            $latestStripeInvoiceId = (string) data_get($latestInvoice?->metadata, 'providers.stripe.invoice_id', '');
            if (
                $latestInvoice
                && ! in_array((string) $latestInvoice->status, ['paid'], true)
                && ($latestStripeInvoiceId === '' || $latestStripeInvoiceId === $stripeInvoiceId)
            ) {
                $invoice = $latestInvoice;
            }
        }

        if (! $invoice) {
            $invoice = Invoice::create([
                'amount' => ((int) data_get($invoiceObject, 'amount_due', 0)) / 100,
                'amount_paid' => ((int) data_get($invoiceObject, 'amount_paid', 0)) / 100,
                'status' => $this->resolveLocalInvoiceStatus($invoiceObject, $fallbackStatus),
                'due_date' => $this->resolveInvoiceDueDate($invoiceObject),
                'subscription_id' => $subscription->id,
                'metadata' => [
                    'provider' => 'stripe',
                ],
            ]);

            $this->ensureInvoiceNumber($invoice);
        }

        $invoiceMetadata = is_array($invoice->metadata) ? $invoice->metadata : [];
        $invoiceMetadata = array_replace_recursive($invoiceMetadata, $this->stripeInvoiceMetadata($invoiceObject));

        $invoice->amount = ((int) data_get($invoiceObject, 'amount_due', 0)) / 100;
        $invoice->amount_paid = ((int) data_get($invoiceObject, 'amount_paid', 0)) / 100;
        $invoice->status = $this->resolveLocalInvoiceStatus($invoiceObject, $fallbackStatus);
        $invoice->due_date = $this->resolveInvoiceDueDate($invoiceObject);
        $invoice->subscription_id = $subscription->id;
        $invoice->metadata = $invoiceMetadata;
        $invoice->save();

        return $invoice->fresh();
    }

    protected function findLocalInvoiceByStripeInvoiceId(string $stripeInvoiceId): ?Invoice
    {
        if ($stripeInvoiceId === '') {
            return null;
        }

        return Invoice::query()
            ->where('metadata->providers->stripe->invoice_id', $stripeInvoiceId)
            ->orWhere('metadata->stripe_invoice_id', $stripeInvoiceId)
            ->first();
    }

    protected function stripeInvoiceMetadata($invoiceObject): array
    {
        return [
            'providers' => [
                'stripe' => [
                    'invoice_id' => (string) data_get($invoiceObject, 'id', ''),
                    'invoice_status' => (string) data_get($invoiceObject, 'status', ''),
                    'billing_reason' => (string) data_get($invoiceObject, 'billing_reason', ''),
                    'payment_intent_id' => (string) data_get($invoiceObject, 'payment_intent', ''),
                    'hosted_invoice_url' => data_get($invoiceObject, 'hosted_invoice_url'),
                    'invoice_pdf' => data_get($invoiceObject, 'invoice_pdf'),
                    'period_start' => data_get($invoiceObject, 'period_start'),
                    'period_end' => data_get($invoiceObject, 'period_end'),
                    'customer_id' => (string) data_get($invoiceObject, 'customer', ''),
                    'subscription_id' => (string) data_get($invoiceObject, 'subscription', ''),
                ],
            ],
        ];
    }

    protected function resolveLocalInvoiceStatus($invoiceObject, string $fallbackStatus): string
    {
        $status = (string) data_get($invoiceObject, 'status', '');

        return match ($status) {
            'paid' => 'paid',
            'void', 'uncollectible' => 'failed',
            'draft', 'open', 'pending' => 'pending',
            default => $fallbackStatus,
        };
    }

    protected function resolveInvoiceDueDate($invoiceObject): \Carbon\CarbonInterface
    {
        $dueTimestamp = data_get($invoiceObject, 'due_date')
            ?? data_get($invoiceObject, 'period_end')
            ?? data_get($invoiceObject, 'created')
            ?? now()->timestamp;

        return now()->setTimestamp((int) $dueTimestamp);
    }

    protected function ensureInvoiceNumber(Invoice $invoice): void
    {
        if ($invoice->invoice_number) {
            return;
        }

        $invoice->invoice_number = 'INV-' . now()->format('Ym') . '-' . str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT);
        $invoice->saveQuietly();
    }

    protected function invoiceProviderReference($invoiceObject, string $fallback = ''): string
    {
        return (string) (
            data_get($invoiceObject, 'id')
            ?: data_get($invoiceObject, 'payment_intent')
            ?: $fallback
        );
    }

    protected function upsertTransactionTelemetry(mixed $subscription, string $providerReference, array $attributes, ?Invoice $invoiceModel = null): ?Transaction
    {
        $invoiceModel ??= Invoice::query()
            ->where('subscription_id', $subscription->id)
            ->latest('id')
            ->first();
        $paymentMethod = PaymentMethod::get('stripe');

        if (! $paymentMethod) {
            return null;
        }

        $transactionQuery = Transaction::query()
            ->when($invoiceModel, fn ($query) => $query->where('invoice_id', $invoiceModel->id));

        $transaction = $providerReference !== ''
            ? (clone $transactionQuery)->where('provider_reference', $providerReference)->first()
            : null;

        if (! $transaction && $invoiceModel) {
            $transaction = (clone $transactionQuery)
                ->where('payment_method_id', $paymentMethod->id)
                ->latest('id')
                ->first();
        }

        if (! $transaction) {
            $transaction = new Transaction([
                'amount' => (float) ($invoiceModel?->amount ?? $subscription->subscriptionPlan?->price ?? 0),
                'currency' => strtoupper((string) ($subscription->subscriptionPlan?->currency ?: 'USD')),
                'invoice_id' => $invoiceModel?->id,
                'user_id' => $subscription->user_id,
                'payment_method_id' => $paymentMethod->id,
                'provider_reference' => $providerReference ?: null,
            ]);
        }

        $attributes = $this->preserveFinalTransactionStatus($transaction, $attributes);
        $existingMetadata = is_array($transaction->metadata) ? $transaction->metadata : [];
        $incomingMetadata = is_array($attributes['metadata'] ?? null) ? $attributes['metadata'] : [];

        $transaction->fill(Arr::except($attributes, ['metadata']));
        $transaction->metadata = array_replace_recursive($existingMetadata, $incomingMetadata);

        if (! $transaction->invoice_id && $invoiceModel) {
            $transaction->invoice_id = $invoiceModel->id;
        }

        if (! $transaction->user_id) {
            $transaction->user_id = $subscription->user_id;
        }

        if (! $transaction->payment_method_id) {
            $transaction->payment_method_id = $paymentMethod->id;
        }

        if ($providerReference !== '' && ! $transaction->provider_reference) {
            $transaction->provider_reference = $providerReference;
        }

        $transaction->save();

        Log::info('billing.stripe_webhook.transaction_upserted', [
            'transaction_id' => $transaction->getKey(),
            'subscription_id' => $subscription->id,
            'invoice_id' => $transaction->invoice_id,
            'provider_reference' => $transaction->provider_reference,
            'status' => $transaction->status,
        ]);

        return $transaction;
    }

    protected function preserveFinalTransactionStatus(Transaction $transaction, array $attributes): array
    {
        $incomingStatus = $attributes['status'] ?? null;
        $currentStatus = (string) ($transaction->status ?? '');

        if (
            $currentStatus === 'completed'
            && in_array($incomingStatus, ['pending', 'failed', 'cancelled', 'action_required'], true)
        ) {
            unset($attributes['status']);
        }

        if (
            $incomingStatus === 'pending'
            && in_array($currentStatus, ['completed', 'cancelled', 'failed', 'refunded'], true)
        ) {
            unset($attributes['status']);
        }

        return $attributes;
    }

    protected function preserveMoreAdvancedSubscriptionStatus(
        mixed $subscription,
        string $normalized,
        string $stripeStatus,
        string $providerId
    ): string {
        $currentStatus = (string) ($subscription->status ?? '');
        $stalePendingStatuses = ['past_due', 'incomplete', 'incomplete_expired', 'unpaid'];

        if ($currentStatus === 'active' && in_array($stripeStatus, $stalePendingStatuses, true)) {
            Log::info('billing.stripe_webhook.subscription_status_preserved', [
                'subscription_id' => $subscription->id,
                'provider_subscription_id' => $providerId,
                'current_status' => $currentStatus,
                'incoming_status' => $normalized,
                'stripe_status' => $stripeStatus,
            ]);

            return $currentStatus;
        }

        return $normalized;
    }
}
