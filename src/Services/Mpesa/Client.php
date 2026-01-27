<?php

namespace Lyre\Billing\Services\Mpesa;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Lyre\Billing\Models\PaymentMethod;
use Lyre\Billing\Models\Transaction;
use Lyre\Billing\Contracts\TransactionRepositoryInterface;

class Client
{
    public PaymentMethod $paymentMethod;

    public function __construct()
    {
        $this->paymentMethod = PaymentMethod::get('mpesa');
    }

    public function getOauthToken()
    {
        if (cache()->has('mpesa_access_token')) {
            return cache()->get('mpesa_access_token');
        }

        $response = Http::asForm()
            ->withBasicAuth(
                $this->paymentMethod->details['MPESA_CONSUMER_KEY'],
                $this->paymentMethod->details['MPESA_CONSUMER_SECRET']
            )
            ->withHeaders([
                'Content-Type' => 'application/json',
            ])
            ->get(
                $this->paymentMethod->details['MPESA_BASE_URI'] .
                    $this->paymentMethod->details['MPESA_OAUTH_URI'],
                [
                    'grant_type' => 'client_credentials',
                ]
            );

        if ($response->successful()) {
            $data = $response->json();

            if (is_null($data)) {
                parse_str($response->body(), $data);
            }

            $expiresIn = $data['expires_in'] ?? 300;

            cache()->put('mpesa_access_token', $data['access_token'], now()->addSeconds($expiresIn - 60));

            return $data['access_token'];
        }

        logger()->error('Mpesa OAuth Error', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        throw new \Exception('Unable to retrieve Mpesa access token.');
    }

    public function express($partyA = null, $phoneNumber, $amount, $paymentMethod = null, $orderReference = null)
    {
        if ($paymentMethod) {
            $this->paymentMethod = $paymentMethod;
        }

        $token = $this->getOauthToken();

        $timestamp = now()->format('YmdHis');
        $password = base64_encode(
            $this->paymentMethod->details['MPESA_BUSINESS_SHORT_CODE'] .
                $this->paymentMethod->details['MPESA_PASSKEY'] .
                $timestamp
        );

        $transactionType = (isset($this->paymentMethod->details['MPESA_PARTY_B']) &&
            $this->paymentMethod->details['MPESA_PARTY_B'] !=
            $this->paymentMethod->details['MPESA_BUSINESS_SHORT_CODE']) ||
            $this->paymentMethod->details['MPESA_BUSINESS_SHORT_CODE'] == 174379 ? 'CustomerPayBillOnline' : 'CustomerBuyGoodsOnline';

        $data = [
            "BusinessShortCode" => $this->paymentMethod->details['MPESA_BUSINESS_SHORT_CODE'], // The number identifying the organization
            "Password" => $password,
            "Timestamp" => $timestamp,
            "TransactionType" => $transactionType, // for Paybill Numbers is `CustomerPayBillOnline`, for Till Numbers is `CustomerBuyGoodsOnline`
            "Amount" => $amount,
            "PartyA" => $partyA ?? $phoneNumber, // The phone number sending money
            "PartyB" => $this->paymentMethod->details['MPESA_PARTY_B'] ?? $this->paymentMethod->details['MPESA_BUSINESS_SHORT_CODE'], // The organization receiving the money
            "PhoneNumber" => $phoneNumber, // The phone receiving the STK push
            "CallBackURL" => config('lyre.billing.mpesa.webhook'),
            "AccountReference" => $orderReference ?? $this->paymentMethod->details['MPESA_ACCOUNT_REFERENCE'] ?? config('app.name'), // Value displayed to customer in the STK Pin Prompt along with Business Name
            "TransactionDesc" => "Payment of X"
        ];

        $response = Http::withToken($token)
            ->withHeaders([
                'Content-Type' => 'application/json',
            ])
            ->post(config('services.mpesa.base_uri') . config('services.mpesa.express_uri'), $data);

        $transactionData = [
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => $amount,
            'raw_request' => json_encode($data),
            'raw_response' => json_encode($response->json()),
            'user_id' => auth()->id() ?? null,
        ];

        // Add order_reference if provided
        if ($orderReference && class_exists(\Lyre\Commerce\Models\Order::class)) {
            $order = \Lyre\Commerce\Models\Order::where('reference', $orderReference)->first();
            if ($order) {
                $transactionData['order_reference'] = $order->reference;
            }
        }

        transactionRepository()->create($transactionData);

        if ($response->successful()) {
            return $response->json();
        }

        logger()->error('Mpesa Express Error', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        throw new \Exception('Unable to send Mpesa express STK Push.');
    }

    public static function handleWebhook(array $data)
    {
        $merchantRequestId = $data['Body']['stkCallback']['MerchantRequestID'];
        $transaction = DB::table((new Transaction())->getTable())
            ->whereRaw("(raw_response::jsonb ->> 'MerchantRequestID') = ?", [$merchantRequestId])
            ->first();

        logger()->info('Mpesa Webhook', [$transaction]);

        if (!$transaction) {
            return __response(
                false,
                "Transaction not found",
                $data,
                get_response_code("transaction_not_found")
            );
        }

        $resultCode = $data['Body']['stkCallback']['ResultCode'];

        $status = $resultCode == 0 ? 'completed' : ($resultCode == 1032 ? 'cancelled' : 'failed');

        $callbackMetadata = $data['Body']['stkCallback']['CallbackMetadata'] ?? [];

        $reference = self::getCallbackMetadataByItemName($callbackMetadata, 'MpesaReceiptNumber');

        $transactionModel = Transaction::find($transaction->id);
        $transactionModel->update([
            'raw_callback' => json_encode($data),
            'status' => $status,
            'provider_reference' => $reference
        ]);

        // Update order status if transaction is linked to an order
        if ($status === 'completed' && $transactionModel->order_reference && class_exists(\Lyre\Commerce\Models\Order::class)) {
            $order = \Lyre\Commerce\Models\Order::where('reference', $transactionModel->order_reference)->first();
            if ($order && in_array($order->status, ['invoiced', 'pending', 'confirmed'])) {
                $order->update(['status' => 'paid']);

                // Emit order paid event if exists
                if (class_exists(\Lyre\Commerce\Events\OrderPaid::class)) {
                    event(new \Lyre\Commerce\Events\OrderPaid($order));
                }
            }
        }

        return __response(
            true,
            "Webhook",
            $data,
            get_response_code("webhook")
        );
    }

    /**
     * Extract callback metadata by item name
     *
     * @param array $callbackMetadata
     * @param string $itemName
     * @return mixed|null
     */
    public static function  getCallbackMetadataByItemName($callbackMetadata, $itemName)
    {
        if (!isset($callbackMetadata['Item']) || !is_array($callbackMetadata['Item'])) {
            return null;
        }

        foreach ($callbackMetadata['Item'] as $item) {
            if (isset($item['Name']) && $item['Name'] === $itemName) {
                return $item['Value'] ?? null;
            }
        }

        return null;
    }
}
