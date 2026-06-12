<?php

namespace Lyre\Billing\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Lyre\Billing\Events\SubscriptionProviderCancelled;
use Lyre\Billing\Events\SubscriptionProviderReturned;
use Lyre\Billing\Models\Invoice;
use Lyre\Billing\Models\PaymentMethod;
use Lyre\Billing\Models\Transaction;
use Lyre\Billing\Services\Paypal\Subscription as PaypalSubscriptionService;

class SubscriptionProviderReturnService
{
    public function handle(array $payload): array
    {
        $provider = $this->resolveProvider($payload);
        $providerSubscriptionId = (string) ($payload['subscription_id'] ?? $payload['ba_token'] ?? '');
        $checkoutSessionId = (string) ($payload['session_id'] ?? '');
        $localSubscriptionId = (string) ($payload['local_subscription_id'] ?? '');
        $localInvoiceId = (string) ($payload['local_invoice_id'] ?? '');
        $returnState = $this->resolveReturnState($payload, $providerSubscriptionId, $checkoutSessionId);
        $subscription = $this->resolveSubscription($provider, $providerSubscriptionId, $checkoutSessionId, $localSubscriptionId);

        Log::info('billing.provider_return.received', [
            'provider' => $provider,
            'provider_subscription_id' => $providerSubscriptionId ?: null,
            'checkout_session_id' => $checkoutSessionId ?: null,
            'local_subscription_id' => $localSubscriptionId ?: null,
            'local_invoice_id' => $localInvoiceId ?: null,
            'return_state' => $returnState,
            'current_user_id' => auth()->id(),
            'subscription_id' => $subscription?->id,
        ]);

        if (! $subscription) {
            return [
                'provider' => $provider,
                'provider_subscription_id' => $providerSubscriptionId ?: null,
                'checkout_session_id' => $checkoutSessionId ?: null,
                'local_subscription_id' => $localSubscriptionId ?: null,
                'local_invoice_id' => $localInvoiceId ?: null,
                'provider_return_state' => $returnState,
                'recorded' => false,
            ];
        }

        $this->reassignGuestOwnedSubscription($subscription);

        $providerResponse = $this->fetchProviderResponse($provider, $providerSubscriptionId);
        $providerKey = $provider === 'stripe' ? 'stripe' : 'paypal';

        $metadata = is_array($subscription->metadata) ? $subscription->metadata : [];
        $returnedAt = now();

        data_set($metadata, "{$providerKey}.last_returned_at", $returnedAt->toIso8601String());
        data_set($metadata, "{$providerKey}.last_return_state", $returnState);
        data_set($metadata, "{$providerKey}.last_return_payload", $payload);

        if ($providerResponse) {
            data_set($metadata, "{$providerKey}.last_return_response", $providerResponse);
        }

        $updates = ['metadata' => $metadata];

        if ($returnState === 'cancelled' && $subscription->status === 'pending') {
            $updates['status'] = 'canceled';
        }

        $subscription->update($updates);

        $invoiceModel = $this->resolveInvoiceModel($subscription, $localInvoiceId);

        $this->upsertTransactionTelemetry(
            $subscription,
            $providerSubscriptionId ?: $checkoutSessionId,
            [
                'status' => $returnState === 'cancelled' ? 'cancelled' : 'pending',
                'raw_callback' => json_encode($payload),
                'raw_response' => $providerResponse ? json_encode($providerResponse) : null,
                'metadata' => [
                    'provider' => $provider,
                    'checkout_session_id' => $checkoutSessionId ?: null,
                    'local_subscription_id' => $localSubscriptionId ?: null,
                    'local_invoice_id' => $localInvoiceId ?: null,
                    'provider_returned_at' => $returnedAt->toIso8601String(),
                    'provider_return_state' => $returnState,
                    'provider_return_payload' => $payload,
                ],
            ],
            $provider,
            $invoiceModel
        );

        if ($returnState === 'cancelled') {
            event(new SubscriptionProviderCancelled($subscription->fresh(), $provider, $returnState, $payload));
        } else {
            event(new SubscriptionProviderReturned($subscription->fresh(), $provider, $returnState, $payload));
        }

        return [
            'provider' => $provider,
            'provider_subscription_id' => $providerSubscriptionId ?: null,
            'checkout_session_id' => $checkoutSessionId ?: null,
            'provider_return_state' => $returnState,
            'recorded' => true,
            'subscription_id' => $subscription->id,
        ];
    }

    public function frontendRedirectUrl(string $status): string
    {
        $baseUrl = rtrim((string) config('billing.client_url', config('app.client_url')), '/');
        $path = auth()->check() && ! $this->userIsGuest(auth()->user()) ? '/dashboard' : '/login';
        $query = http_build_query(['billing_status' => $status]);

        return "{$baseUrl}{$path}".($query ? "?{$query}" : '');
    }

    protected function resolveProvider(array $payload): string
    {
        $provider = strtolower((string) Arr::get($payload, 'provider', ''));
        if ($provider !== '') {
            return $provider;
        }

        if (Arr::has($payload, 'session_id')) {
            return 'stripe';
        }

        return 'paypal';
    }

    protected function resolveSubscription(string $provider, string $providerSubscriptionId, string $checkoutSessionId, string $localSubscriptionId = ''): mixed
    {
        if ($localSubscriptionId !== '') {
            return $this->findByLocalSubscriptionId($localSubscriptionId);
        }

        if ($provider === 'stripe' && $checkoutSessionId !== '') {
            return $this->findByStripeCheckoutSessionId($checkoutSessionId);
        }

        if ($providerSubscriptionId !== '') {
            try {
                return $this->findByProviderSubscriptionId($providerSubscriptionId, $provider);
            } catch (ModelNotFoundException) {
                return null;
            }
        }

        return $this->resolveLatestPendingSubscriptionForCurrentUser();
    }

    protected function resolveReturnState(array $payload, string $providerSubscriptionId, string $checkoutSessionId): string
    {
        if ($providerSubscriptionId !== '' || $checkoutSessionId !== '') {
            return 'returned';
        }

        if (Arr::get($payload, 'cancelled') || Arr::get($payload, 'canceled')) {
            return 'cancelled';
        }

        if (Arr::has($payload, 'token') || Arr::has($payload, 'PayerID')) {
            return 'cancelled';
        }

        return 'unknown';
    }

    protected function fetchProviderResponse(string $provider, string $providerSubscriptionId): mixed
    {
        if ($provider !== 'paypal' || $providerSubscriptionId === '') {
            return null;
        }

        try {
            return PaypalSubscriptionService::getSubscriptionDetails($providerSubscriptionId);
        } catch (\Throwable $exception) {
            report($exception);
        }

        return null;
    }

    protected function reassignGuestOwnedSubscription(mixed $subscription): void
    {
        $user = auth()->user();
        $owner = $subscription->user;

        if (! $user || $this->userIsGuest($user)) {
            return;
        }

        if (! $owner || ! $this->userIsGuest($owner) || $owner->id === $user->id) {
            return;
        }

        Log::info('billing.provider_return.reassigning_subscription_owner', [
            'subscription_id' => $subscription->id,
            'from_user_id' => $owner->id,
            'to_user_id' => $user->id,
        ]);

        $subscription->update(['user_id' => $user->id]);
    }

    protected function userIsGuest(mixed $user): bool
    {
        if (! $user) {
            return false;
        }

        if (method_exists($user, 'isGuest')) {
            return (bool) $user->isGuest();
        }

        return (bool) data_get($user, 'is_guest', false);
    }

    protected function findByProviderSubscriptionId(string $providerSubscriptionId, string $provider = 'paypal'): mixed
    {
        $query = $this->subscriptionQueryWithoutScopes();

        if ($provider === 'stripe') {
            return $query
                ->where(function (Builder $builder) use ($providerSubscriptionId) {
                    $builder->where('metadata->providers->stripe->subscription_id', $providerSubscriptionId)
                        ->orWhere('metadata->stripe_subscription_id', $providerSubscriptionId);
                })
                ->firstOrFail();
        }

        return $query
            ->where(function (Builder $builder) use ($providerSubscriptionId) {
                $builder->where('metadata->providers->paypal->subscription_id', $providerSubscriptionId)
                    ->orWhere('metadata->paypal_subscription_id', $providerSubscriptionId);
            })
            ->firstOrFail();
    }

    protected function findByStripeCheckoutSessionId(string $checkoutSessionId): mixed
    {
        return $this->subscriptionQueryWithoutScopes()
            ->where('metadata->providers->stripe->checkout_session_id', $checkoutSessionId)
            ->first();
    }

    protected function resolveLatestPendingSubscriptionForCurrentUser(): mixed
    {
        $user = auth()->user();
        if (! $user || $this->userIsGuest($user)) {
            return null;
        }

        return $this->subscriptionQueryWithoutScopes()
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->latest('id')
            ->first();
    }

    protected function subscriptionQueryWithoutScopes(): Builder
    {
        $subscriptionClass = config('billing.models.subscription');
        $subscriptionModel = app($subscriptionClass);

        return $subscriptionModel->newQueryWithoutScopes();
    }

    protected function findByLocalSubscriptionId(string $subscriptionId): mixed
    {
        return $this->subscriptionQueryWithoutScopes()
            ->whereKey($subscriptionId)
            ->first();
    }

    protected function resolveInvoiceModel(mixed $subscription, string $localInvoiceId = ''): ?Invoice
    {
        if ($localInvoiceId !== '') {
            return Invoice::query()->find($localInvoiceId);
        }

        return Invoice::query()
            ->where('subscription_id', $subscription->id)
            ->latest('id')
            ->first();
    }

    protected function upsertTransactionTelemetry(mixed $subscription, string $providerReference, array $attributes, string $provider, ?Invoice $invoiceModel = null): ?Transaction
    {
        $invoiceModel ??= Invoice::query()
            ->where('subscription_id', $subscription->id)
            ->latest('id')
            ->first();
        $paymentMethod = PaymentMethod::get($provider);

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

        $attributes = $this->preserveFinalTransactionStatus($transaction, $attributes, $provider);
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

        Log::info('billing.provider_return.transaction_upserted', [
            'provider' => $provider,
            'transaction_id' => $transaction->getKey(),
            'subscription_id' => $subscription->id,
            'invoice_id' => $transaction->invoice_id,
            'provider_reference' => $transaction->provider_reference,
            'status' => $transaction->status,
        ]);

        return $transaction;
    }

    protected function preserveFinalTransactionStatus(Transaction $transaction, array $attributes, string $provider): array
    {
        $incomingStatus = $attributes['status'] ?? null;
        $currentStatus = (string) ($transaction->status ?? '');

        if (
            $provider === 'stripe'
            && $incomingStatus === 'pending'
            && in_array($currentStatus, ['completed', 'cancelled', 'failed', 'refunded'], true)
        ) {
            unset($attributes['status']);
        }

        return $attributes;
    }
}
