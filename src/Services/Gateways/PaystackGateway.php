<?php

namespace Lyre\Billing\Services\Gateways;

use Lyre\Billing\Contracts\TransactionRepositoryInterface;

class PaystackGateway extends BaseGateway
{
    public function providerKey(): string
    {
        return 'paystack';
    }

    public function label(): string
    {
        return 'Paystack';
    }

    public function logo(): string
    {
        return '/images/payments/paystack.svg';
    }

    public function isEnabled(): bool
    {
        return filled(config('services.paystack.secret'));
    }

    public function initiate(array $payload): array
    {
        $method = $this->paymentMethod('paystack');
        $transaction = app(TransactionRepositoryInterface::class)->create([
            'payment_method_id' => $method?->id,
            'status' => 'pending',
            'amount' => $this->normalizeAmount($payload['amount'] ?? 0),
            'currency' => $payload['currency'] ?? 'KES',
            'order_reference' => $payload['order_reference'] ?? null,
            'provider_reference' => 'paystack_' . strtolower((string) \Illuminate\Support\Str::uuid()),
            'raw_request' => json_encode($payload),
            'raw_response' => json_encode(['mode' => 'initialize_placeholder']),
            'user_id' => $payload['user_id'] ?? auth()->id(),
        ])->resource;

        return [
            'status' => 'pending',
            'message' => $this->isEnabled()
                ? 'Paystack initiation scaffolded. Plug provider API initialization and redirect URL.'
                : 'Paystack is not configured yet. Add PAYSTACK_SECRET.',
            'provider_reference' => $transaction?->provider_reference,
            'order_reference' => (string) ($payload['order_reference'] ?? ''),
        ];
    }

    public function handleCallback(array $payload): array
    {
        $reference = (string) ($payload['data']['reference'] ?? $payload['reference'] ?? '');
        $transaction = $this->findTransactionByProviderReference($reference);

        $status = (string) ($payload['data']['status'] ?? $payload['status'] ?? 'pending');
        $normalized = match ($status) {
            'success', 'successful', 'completed' => 'completed',
            'failed' => 'failed',
            'abandoned', 'cancelled', 'canceled' => 'cancelled',
            default => 'pending',
        };

        $this->updateTransactionStatus($transaction, $normalized, $payload);

        return [
            'status' => $normalized,
            'message' => 'Paystack callback handled.',
            'provider_reference' => $reference,
            'order_reference' => (string) ($transaction?->order_reference ?? ''),
        ];
    }

    public function handleReturn(array $payload): array
    {
        return [
            'status' => (string) ($payload['status'] ?? 'pending'),
            'message' => 'Paystack return handled.',
            'provider_reference' => (string) ($payload['reference'] ?? ''),
            'order_reference' => (string) ($payload['order_reference'] ?? $payload['order'] ?? ''),
        ];
    }
}
