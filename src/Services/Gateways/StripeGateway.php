<?php

namespace Lyre\Billing\Services\Gateways;

use Lyre\Billing\Contracts\TransactionRepositoryInterface;

class StripeGateway extends BaseGateway
{
    public function providerKey(): string
    {
        return 'stripe';
    }

    public function label(): string
    {
        return 'Stripe';
    }

    public function logo(): string
    {
        return '/images/payments/stripe.svg';
    }

    public function isEnabled(): bool
    {
        return filled(config('services.stripe.key')) && filled(config('services.stripe.secret'));
    }

    public function initiate(array $payload): array
    {
        $method = $this->paymentMethod('stripe');
        $transaction = app(TransactionRepositoryInterface::class)->create([
            'payment_method_id' => $method?->id,
            'status' => 'pending',
            'amount' => $this->normalizeAmount($payload['amount'] ?? 0),
            'currency' => $payload['currency'] ?? 'USD',
            'order_reference' => $payload['order_reference'] ?? null,
            'provider_reference' => 'stripe_' . strtolower((string) \Illuminate\Support\Str::uuid()),
            'raw_request' => json_encode($payload),
            'raw_response' => json_encode(['mode' => 'checkout_session_placeholder']),
            'user_id' => $payload['user_id'] ?? auth()->id(),
        ])->resource;

        return [
            'status' => 'pending',
            'message' => $this->isEnabled()
                ? 'Stripe session scaffolded. Complete Stripe API wiring with your credentials.'
                : 'Stripe is not configured yet. Add STRIPE_KEY and STRIPE_SECRET.',
            'provider_reference' => $transaction?->provider_reference,
            'order_reference' => (string) ($payload['order_reference'] ?? ''),
        ];
    }

    public function handleCallback(array $payload): array
    {
        return [
            'status' => 'pending',
            'message' => 'Stripe webhook received. Map event types to completed/failed transitions.',
            'provider_reference' => (string) ($payload['id'] ?? ''),
            'order_reference' => (string) ($payload['metadata']['order_reference'] ?? ''),
        ];
    }

    public function handleReturn(array $payload): array
    {
        return [
            'status' => (string) ($payload['status'] ?? 'pending'),
            'message' => 'Stripe return handled.',
            'provider_reference' => (string) ($payload['session_id'] ?? ''),
            'order_reference' => (string) ($payload['order_reference'] ?? $payload['order'] ?? ''),
        ];
    }
}
