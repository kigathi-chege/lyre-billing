<?php

namespace Lyre\Billing\Services\Paypal;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Lyre\Billing\Models\Invoice;
use Lyre\Billing\Models\PaymentMethod;
use Lyre\Billing\Models\Transaction;
use Lyre\Billing\Services\SubscriptionLifecycleService;

class WebhookEventHandler
{
    public function handle(Request $request): void
    {
        $data = $request->all();
        $eventType = data_get($data, 'event_type');

        match ($eventType) {
            'PAYMENT.CAPTURE.COMPLETED' => $this->paymentCaptureCompleted($data),
            'PAYMENT.SALE.COMPLETED' => $this->paymentSaleCompleted($data),
            'BILLING.SUBSCRIPTION.ACTIVATED' => $this->billingSubscriptionActivated($data),
            'BILLING.SUBSCRIPTION.SUSPENDED' => $this->billingSubscriptionSuspended($data),
            'BILLING.SUBSCRIPTION.PAYMENT.FAILED' => $this->billingSubscriptionPaymentFailed($data),
            default => null,
        };
    }

    protected function paymentCaptureCompleted(array $data): void
    {
        $orderId = data_get($data, 'resource.supplementary_data.related_ids.order_id');

        if (! $orderId) {
            return;
        }

        try {
            Payment::capture($orderId);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('PayPal Capture Webhook Error', [
                'error' => $e->getMessage(),
                'data' => $data,
            ]);
        }
    }

    protected function paymentSaleCompleted(array $data): void
    {
        $providerId = data_get($data, 'resource.billing_agreement_id');
        if (! $providerId) {
            return;
        }

        $invoice = $this->retrieveInvoice($data);
        $this->recordWebhookTelemetry($data, $providerId, $invoice);
        app(SubscriptionLifecycleService::class)->approveByProviderId($providerId, $invoice, 'paypal');

        if ($invoice) {
            $invoice->update([
                'amount_paid' => data_get($data, 'resource.amount.total'),
                'status' => 'paid',
            ]);
        }
    }

    protected function billingSubscriptionActivated(array $data): void
    {
        $providerId = data_get($data, 'resource.id');
        if (! $providerId) {
            return;
        }

        $invoice = $this->retrieveInvoice($data);
        $this->recordWebhookTelemetry($data, $providerId, $invoice);
        $lastPaymentAmount = data_get($data, 'resource.billing_info.last_payment.amount.value');

        if ($lastPaymentAmount !== null) {
            if ($invoice) {
                $invoice->update([
                    'amount_paid' => $lastPaymentAmount,
                    'status' => data_get($data, 'resource.billing_info.outstanding_balance.value') == '0.00' ? 'paid' : 'pending',
                ]);
            }

            return;
        }

        $isSettledTrialActivation = data_get($data, 'resource.billing_info.outstanding_balance.value') == '0.00'
            && (int) data_get($data, 'resource.billing_info.failed_payments_count', 0) === 0;

        if (! $isSettledTrialActivation) {
            return;
        }

        app(SubscriptionLifecycleService::class)->approveByProviderId($providerId, $invoice, 'paypal');

        if ($invoice) {
            $invoice->update([
                'amount_paid' => 0,
                'status' => 'paid',
            ]);
        }
    }

    protected function billingSubscriptionSuspended(array $data): void
    {
        $providerId = data_get($data, 'resource.id');
        if (! $providerId) {
            return;
        }

        $this->recordWebhookTelemetry($data, $providerId);
        app(SubscriptionLifecycleService::class)->suspendByProviderId($providerId, 'paypal');
    }

    protected function billingSubscriptionPaymentFailed(array $data): void
    {
        $providerId = data_get($data, 'resource.id');
        if (! $providerId) {
            return;
        }

        $invoice = $this->retrieveInvoice($data);
        $this->recordWebhookTelemetry($data, $providerId, $invoice);
        if ($invoice) {
            $invoice->update([
                'amount_paid' => data_get($data, 'resource.amount.total'),
                'status' => 'paid',
            ]);
        }

        app(SubscriptionLifecycleService::class)->paymentFailedByProviderId($providerId, $invoice, 'paypal');
    }

    protected function retrieveInvoice(array $data): ?Invoice
    {
        $invoiceNumber = data_get($data, 'resource.custom_id') ?? data_get($data, 'resource.custom');
        if (! $invoiceNumber) {
            return null;
        }

        return Invoice::where('invoice_number', $invoiceNumber)->first();
    }

    protected function recordWebhookTelemetry(array $payload, ?string $providerSubscriptionId = null, ?Invoice $invoice = null): void
    {
        $providerSubscriptionId = $providerSubscriptionId
            ?: (string) (data_get($payload, 'resource.billing_agreement_id') ?: data_get($payload, 'resource.id') ?: '');

        if ($providerSubscriptionId === '') {
            return;
        }

        $subscription = PaypalModelBridge::findSubscriptionByProviderId($providerSubscriptionId);
        $receivedAt = now();
        $metadata = is_array($subscription->metadata) ? $subscription->metadata : [];

        data_set($metadata, 'paypal.last_webhook_received_at', $receivedAt->toIso8601String());
        data_set($metadata, 'paypal.last_webhook_event', data_get($payload, 'event_type'));
        $subscription->update(['metadata' => $metadata]);

        $transaction = $this->upsertTransactionTelemetry(
            $subscription,
            $providerSubscriptionId,
            [
                'status' => $this->resolveTransactionStatusFromWebhook((string) data_get($payload, 'event_type')),
                'raw_callback' => json_encode($payload),
                'metadata' => [
                    'provider' => 'paypal',
                    'webhook_received_at' => $receivedAt->toIso8601String(),
                    'webhook_event_type' => data_get($payload, 'event_type'),
                ],
            ],
            $invoice
        );

        if (! $transaction) {
            return;
        }

        $transactionMetadata = is_array($transaction->metadata) ? $transaction->metadata : [];
        $providerReturnedAt = data_get($transactionMetadata, 'provider_returned_at');

        if (is_string($providerReturnedAt)) {
            try {
                $returnMoment = Carbon::parse($providerReturnedAt);
                data_set(
                    $transactionMetadata,
                    'provider_return_to_webhook_ms',
                    $returnMoment->diffInMilliseconds($receivedAt, false)
                );
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        $transaction->update(['metadata' => $transactionMetadata]);
    }

    protected function upsertTransactionTelemetry(
        mixed $subscription,
        string $providerReference,
        array $attributes,
        ?Invoice $invoiceModel = null
    ): ?Transaction {
        $invoiceModel ??= Invoice::query()
            ->where('subscription_id', $subscription->id)
            ->latest('id')
            ->first();
        $paymentMethod = PaymentMethod::get('paypal');

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

        return $transaction;
    }

    protected function resolveTransactionStatusFromWebhook(string $eventType): string
    {
        return match ($eventType) {
            'PAYMENT.SALE.COMPLETED',
            'BILLING.SUBSCRIPTION.ACTIVATED' => 'completed',
            'BILLING.SUBSCRIPTION.SUSPENDED' => 'cancelled',
            'BILLING.SUBSCRIPTION.PAYMENT.FAILED' => 'failed',
            default => 'pending',
        };
    }
}
