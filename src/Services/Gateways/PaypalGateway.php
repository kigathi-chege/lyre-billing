<?php

namespace Lyre\Billing\Services\Gateways;

use Illuminate\Support\Arr;
use Lyre\Billing\Models\Transaction;
use Lyre\Billing\Services\Paypal\Payment;
use Lyre\Commerce\Models\Order;

class PaypalGateway extends BaseGateway
{
    public function providerKey(): string
    {
        return 'paypal';
    }

    public function label(): string
    {
        return 'PayPal';
    }

    public function logo(): string
    {
        return '/images/payments/paypal.svg';
    }

    public function isEnabled(): bool
    {
        $method = $this->paymentMethod('paypal');

        return (bool) ($method && filled(data_get($method->details, 'PAYPAL_CLIENT_ID')) && filled(data_get($method->details, 'PAYPAL_SECRET')));
    }

    public function initiate(array $payload): array
    {
        if (!$this->isEnabled()) {
            return [
                'status' => 'pending',
                'message' => 'PayPal is not configured yet. Add credentials in Payment Methods.',
                'order_reference' => (string) ($payload['order_reference'] ?? ''),
            ];
        }

        $order = Order::query()->where('reference', $payload['order_reference'])->first();
        if (!$order) {
            return [
                'status' => 'failed',
                'message' => 'Order not found.',
                'order_reference' => (string) ($payload['order_reference'] ?? ''),
            ];
        }

        $payment = new Payment();
        $response = $payment->create($order, [
            'currency' => $payload['currency'] ?? 'USD',
            'description' => $payload['description'] ?? 'Marketplace order payment',
            'return_url' => $payload['return_url'] ?? null,
            'cancel_url' => $payload['cancel_url'] ?? null,
        ]);

        return [
            'status' => 'pending',
            'message' => 'Redirecting to PayPal for approval.',
            'provider_reference' => (string) Arr::get($response, 'id', ''),
            'approval_url' => Arr::get($response, 'approval_url'),
            'order_reference' => (string) ($payload['order_reference'] ?? ''),
        ];
    }

    public function handleCallback(array $payload): array
    {
        $eventType = (string) Arr::get($payload, 'event_type', '');

        if ($eventType === 'PAYMENT.CAPTURE.COMPLETED') {
            $providerRef = (string) Arr::get($payload, 'resource.supplementary_data.related_ids.order_id', '');
            $transaction = Transaction::query()->where('provider_reference', $providerRef)->first();
            $this->updateTransactionStatus($transaction, 'completed', $payload);

            return [
                'status' => 'completed',
                'message' => 'PayPal payment captured.',
                'provider_reference' => $providerRef,
                'order_reference' => (string) ($transaction?->order_reference ?? ''),
            ];
        }

        return [
            'status' => 'pending',
            'message' => 'Webhook accepted.',
        ];
    }

    public function handleReturn(array $payload): array
    {
        $token = (string) ($payload['token'] ?? '');
        if ($token === '') {
            return [
                'status' => 'failed',
                'message' => 'Missing PayPal return token.',
                'order_reference' => (string) ($payload['order_reference'] ?? $payload['order'] ?? ''),
            ];
        }

        Payment::capture($token);

        $transaction = Transaction::query()->where('provider_reference', $token)->first();

        return [
            'status' => 'completed',
            'message' => 'PayPal payment completed.',
            'provider_reference' => $token,
            'order_reference' => (string) ($transaction?->order_reference ?? $payload['order_reference'] ?? $payload['order'] ?? ''),
        ];
    }
}
