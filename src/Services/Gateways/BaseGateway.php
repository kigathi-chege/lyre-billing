<?php

namespace Lyre\Billing\Services\Gateways;

use Lyre\Billing\Contracts\PaymentGatewayInterface;
use Lyre\Billing\Models\PaymentMethod;
use Lyre\Billing\Models\Transaction;

abstract class BaseGateway implements PaymentGatewayInterface
{
    public function logo(): string
    {
        return '';
    }

    protected function normalizeAmount(mixed $value): float
    {
        return (float) ((int) preg_replace('/[^0-9]/', '', (string) ($value ?? 0)));
    }

    protected function paymentMethod(string $key): ?PaymentMethod
    {
        return PaymentMethod::get($key);
    }

    protected function findTransactionByProviderReference(?string $providerReference): ?Transaction
    {
        if (!$providerReference) {
            return null;
        }

        return Transaction::query()->where('provider_reference', $providerReference)->first();
    }

    protected function updateTransactionStatus(?Transaction $transaction, string $status, array $callback = []): void
    {
        if (!$transaction) {
            return;
        }

        $transaction->update([
            'status' => $status,
            'raw_callback' => json_encode($callback),
        ]);

        if ($transaction->order_reference && class_exists(\Lyre\Commerce\Models\Order::class)) {
            $order = \Lyre\Commerce\Models\Order::query()->where('reference', $transaction->order_reference)->first();
            if ($order) {
                $order->status = match ($status) {
                    'completed' => 'paid',
                    'failed' => 'payment_failed',
                    'cancelled' => 'cancelled',
                    default => $order->status,
                };
                $order->save();
            }
        }
    }
}
