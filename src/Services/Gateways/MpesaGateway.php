<?php

namespace Lyre\Billing\Services\Gateways;

use Illuminate\Support\Arr;
use Lyre\Billing\Services\Mpesa\Client;

class MpesaGateway extends BaseGateway
{
    public function providerKey(): string
    {
        return 'mpesa';
    }

    public function label(): string
    {
        return 'M-Pesa';
    }

    public function logo(): string
    {
        return '/images/payments/mpesa.svg';
    }

    public function isEnabled(): bool
    {
        $method = $this->paymentMethod('mpesa');

        return (bool) ($method && filled(data_get($method->details, 'MPESA_CONSUMER_KEY')) && filled(data_get($method->details, 'MPESA_CONSUMER_SECRET')));
    }

    public function initiate(array $payload): array
    {
        if (!$this->isEnabled()) {
            return [
                'status' => 'pending',
                'message' => 'M-Pesa is not configured yet. Add credentials in Payment Methods.',
                'order_reference' => (string) ($payload['order_reference'] ?? ''),
            ];
        }

        $phone = $payload['phone'] ?? $payload['phone_number'] ?? null;
        if (!$phone) {
            return [
                'status' => 'failed',
                'message' => 'Phone number is required for M-Pesa payments.',
                'order_reference' => (string) ($payload['order_reference'] ?? ''),
            ];
        }

        $client = app(Client::class);
        $response = $client->express(
            partyA: $payload['party_a'] ?? null,
            phoneNumber: $phone,
            amount: $this->normalizeAmount($payload['amount'] ?? 0),
            paymentMethod: null,
            orderReference: $payload['order_reference'] ?? null,
        );

        return [
            'status' => 'pending',
            'message' => 'M-Pesa prompt sent to phone.',
            'provider_reference' => (string) Arr::get($response, 'CheckoutRequestID', ''),
            'order_reference' => (string) ($payload['order_reference'] ?? ''),
        ];
    }

    public function handleCallback(array $payload): array
    {
        $response = Client::handleWebhook($payload);

        $resultCode = (int) Arr::get($payload, 'Body.stkCallback.ResultCode', 1);
        $merchantRequestId = (string) Arr::get($payload, 'Body.stkCallback.MerchantRequestID', '');

        return [
            'status' => $resultCode === 0 ? 'completed' : ($resultCode === 1032 ? 'cancelled' : 'failed'),
            'message' => is_string($response) ? $response : 'Webhook processed.',
            'provider_reference' => $merchantRequestId,
            'order_reference' => '',
        ];
    }

    public function handleReturn(array $payload): array
    {
        return [
            'status' => 'pending',
            'message' => 'M-Pesa confirmation is asynchronous. Refresh order status shortly.',
            'order_reference' => (string) ($payload['order_reference'] ?? $payload['order'] ?? ''),
        ];
    }
}
