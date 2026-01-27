<?php

namespace Lyre\Billing\Services\Paypal;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Lyre\Billing\Models\PaymentMethod;
use Lyre\Billing\Models\Transaction;
use Lyre\Billing\Contracts\TransactionRepositoryInterface;

class Payment
{
    protected PaymentMethod $paymentMethod;

    public function __construct()
    {
        $this->paymentMethod = PaymentMethod::get('paypal');
    }

    /**
     * Create a PayPal payment for an order
     */
    public function create($order, array $payload)
    {
        $token = Client::getOauthToken();
        
        $amount = $order->total_amount ?? $payload['amount'] ?? 0;
        $currency = $order->currency ?? $payload['currency'] ?? 'USD';
        
        $paymentData = [
            'intent' => 'CAPTURE',
            'purchase_units' => [
                [
                    'reference_id' => $order->reference ?? uniqid('order_'),
                    'description' => $payload['description'] ?? "Payment for Order #{$order->reference}",
                    'custom_id' => $order->reference ?? uniqid('order_'),
                    'amount' => [
                        'currency_code' => $currency,
                        'value' => number_format($amount, 2, '.', ''),
                    ],
                ],
            ],
            'application_context' => [
                'brand_name' => config('app.name'),
                'locale' => config('app.locale', 'en-US'),
                'landing_page' => 'BILLING',
                'shipping_preference' => 'NO_SHIPPING',
                'user_action' => 'PAY_NOW',
                'return_url' => config('services.paypal.return_url') . '?order=' . ($order->reference ?? $order->id),
                'cancel_url' => config('services.paypal.cancel_url') . '?order=' . ($order->reference ?? $order->id),
            ],
        ];

        try {
            $response = Http::withToken($token)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Prefer' => 'return=representation',
                ])
                ->post(config('services.paypal.base_uri') . '/v2/checkout/orders', $paymentData);

            if ($response->successful()) {
                $paymentResponse = $response->json();
                
                // Create transaction record
                app(TransactionRepositoryInterface::class)->create([
                    'payment_method_id' => $this->paymentMethod->id,
                    'amount' => $amount,
                    'currency' => $currency,
                    'raw_request' => json_encode($paymentData),
                    'raw_response' => json_encode($paymentResponse),
                    'user_id' => $order->customer_id ?? auth()->id(),
                    'order_reference' => $order->reference ?? null,
                    'status' => 'pending',
                    'provider_reference' => $paymentResponse['id'] ?? null,
                ]);

                // Find approval link
                $approvalLink = collect($paymentResponse['links'] ?? [])
                    ->where('rel', 'approve')
                    ->first();

                return [
                    'id' => $paymentResponse['id'],
                    'status' => $paymentResponse['status'],
                    'approval_url' => $approvalLink['href'] ?? null,
                    'links' => $paymentResponse['links'] ?? [],
                ];
            }

            Log::error('PayPal Create Payment Error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \Exception('PayPal payment creation failed.');
        } catch (\Exception $e) {
            Log::error('PayPal Payment Exception', [
                'message' => $e->getMessage(),
                'order' => $order->reference ?? $order->id,
            ]);
            throw $e;
        }
    }

    /**
     * Capture a PayPal payment
     */
    public static function capture(string $orderId)
    {
        $token = Client::getOauthToken();

        $response = Http::withToken($token)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Prefer' => 'return=representation',
            ])
            ->post(config('services.paypal.base_uri') . "/v2/checkout/orders/{$orderId}/capture");

        if ($response->successful()) {
            $captureResponse = $response->json();
            
            // Update transaction status
            $transaction = Transaction::where('provider_reference', $orderId)->first();
            if ($transaction) {
                $status = $captureResponse['status'] === 'COMPLETED' ? 'completed' : 'pending';
                $transaction->update([
                    'status' => $status,
                    'raw_callback' => json_encode($captureResponse),
                ]);

                // Update order status if linked
                if ($transaction->order_reference && class_exists(\Lyre\Commerce\Models\Order::class)) {
                    $order = \Lyre\Commerce\Models\Order::where('reference', $transaction->order_reference)->first();
                    if ($order && $status === 'completed') {
                        $order->update(['status' => 'paid']);
                        
                        // Emit order paid event if exists
                        if (class_exists(\Lyre\Commerce\Events\OrderPaid::class)) {
                            event(new \Lyre\Commerce\Events\OrderPaid($order));
                        }
                    }
                }
            }

            return $captureResponse;
        }

        Log::error('PayPal Capture Payment Error', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        throw new \Exception('PayPal payment capture failed.');
    }

    /**
     * Get payment details
     */
    public static function getDetails(string $orderId)
    {
        $token = Client::getOauthToken();

        $response = Http::withToken($token)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->get(config('services.paypal.base_uri') . "/v2/checkout/orders/{$orderId}");

        if ($response->successful()) {
            return $response->json();
        }

        Log::error('PayPal Get Payment Details Error', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        throw new \Exception('Unable to retrieve payment details.');
    }
}

