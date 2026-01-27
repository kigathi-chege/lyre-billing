<?php

namespace Lyre\Billing\Services\Paypal;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Lyre\Billing\Models\Subscription as ModelsSubscription;

class Subscription
{
    public static function fromSubscription(ModelsSubscription $subscription, $invoiceNumber = null)
    {
        if ($subscription->metadata['paypal_subscription_id']) {
            return self::getSubscriptionDetails($subscription->metadata['paypal_subscription_id']);
        }

        $time = now()->addMinutes(5);
        $subscription->update(['start_time' => $time]);

        $payload = [
            'plan_id' => $subscription->subscriptionPlan->metadata['paypal_plan_id'],
            'start_time' => $time->setTimezone(config('app.timezone'))->format('Y-m-d\TH:i:s\Z'),
            'custom_id' => $invoiceNumber,
            'quantity' => '1',
            'subscriber' => [
                'name' => [
                    'given_name' => explode(" ", $subscription->name)[0],
                    'surname' => explode(" ", $subscription->name)[1] ?? explode(" ", $subscription->name)[0],
                ],
                'email_address' => $subscription->email,
                'shipping_address' => [
                    'name' => ['full_name' => $subscription->name],
                    'address' => [
                        'address_line_1' => $subscription->address_line_1 ?? '1234 Elm Street',
                        'address_line_2' => $subscription->address_line_2 ?? 'Suite 100',
                        'admin_area_2' => $subscription->admin_area_2 ?? 'San Jose',
                        'admin_area_1' => $subscription->admin_area_1 ?? 'CA',
                        'postal_code' => $subscription->postal_code ?? '95131',
                        'country_code' => $subscription->country_code ?? 'US',
                    ],
                ],
            ],
            'application_context' => [
                'brand_name' => config('app.name'),
                'locale' => config('app.locale'),
                'shipping_preference' => 'SET_PROVIDED_ADDRESS',
                'user_action' => 'SUBSCRIBE_NOW',
                'payment_method' => [
                    'payer_selected' => 'PAYPAL',
                    'payee_preferred' => 'IMMEDIATE_PAYMENT_REQUIRED',
                ],
                'return_url' => config('services.paypal.return_url'),
                'cancel_url' => config('services.paypal.cancel_url'),
            ],
        ];

        Log::info("PAYPAL SUBSCRIPTION PAYLOAD", [$payload]);

        try {
            $paypalSubscription = self::createPaypalSubscription($payload);
            Log::info("PAYPAL SUBSCRIPTION", [$paypalSubscription]);
            $subscription->metadata['paypal_subscription_id'] = $paypalSubscription['id'];
            $subscription->save();

            $approvalLink = collect($paypalSubscription['links'])->where('rel', 'approve')->first();
            $subscription->update(['link' => $approvalLink['href']]);

            return $paypalSubscription['links'];
        } catch (\Exception $e) {
            report($e);
        }
    }

    public static function createPaypalSubscription(array $payload)
    {
        $token = Client::getOauthToken(); // This should return a valid access token
        $requestId = uniqid();

        $response = Http::withToken($token)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'PayPal-Request-Id' => $requestId,
                'Prefer' => 'return=representation', // or 'return=minimal'
            ])
            ->post(config('services.paypal.base_uri') . '/v1/billing/subscriptions', $payload);

        if ($response->successful()) {
            return $response->json(); // Will include subscription id, status, links etc.
        }

        Log::error('PayPal Create Subscription Error', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        throw new \Exception('PayPal subscription creation failed.');
    }

    public static function getSubscriptionDetails(string $subscriptionId): array
    {
        $token = Client::getOauthToken();

        $response = Http::withToken($token)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->get(config('services.paypal.base_uri') . "/v1/billing/subscriptions/{$subscriptionId}");

        if ($response->successful()) {
            return $response->json();
        }

        Log::error('PayPal Get Subscription Details Error', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        throw new \Exception('Unable to retrieve subscription details.');
    }

    /**
     * Send a PATCH request to update a subscription with given operations.
     *
     * @param string $subscriptionId
     * @param array $operations Array of patch operations
     * @return bool
     * @throws \Exception
     */
    public static function updateSubscription(string $subscriptionId, array $operations): bool
    {
        $token = Client::getOauthToken();

        $response = Http::withToken($token)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->patch(config('services.paypal.base_uri') . "/v1/billing/subscriptions/{$subscriptionId}", $operations);

        if ($response->noContent()) {
            return true;
        }

        Log::error('PayPal Update Subscription Error', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        throw new \Exception('Unable to update subscription.');
    }

    // Updates outstanding_balance (replace)
    public static function updateOutstandingBalance(string $subscriptionId, array $balance): bool
    {
        return self::updateSubscription($subscriptionId, [[
            'op' => 'replace',
            'path' => '/billing_info/outstanding_balance',
            'value' => $balance,
        ]]);
    }

    // Add or replace custom_id
    public static function updateCustomId(string $subscriptionId, string $customId, string $op = 'replace'): bool
    {
        return self::updateSubscription($subscriptionId, [[
            'op' => $op, // 'add' or 'replace'
            'path' => '/custom_id',
            'value' => $customId,
        ]]);
    }

    // Update fixed_price of a specific billing cycle (sequence number n)
    public static function updateFixedPrice(string $subscriptionId, int $sequence, array $fixedPrice): bool
    {
        return self::updateSubscription($subscriptionId, [[
            'op' => 'add', // or 'replace'
            'path' => "/plan/billing_cycles/{$sequence}/pricing_scheme/fixed_price",
            'value' => $fixedPrice,
        ]]);
    }

    // Replace pricing_scheme tiers for a specific billing cycle
    public static function updatePricingSchemeTiers(string $subscriptionId, int $sequence, array $tiers): bool
    {
        return self::updateSubscription($subscriptionId, [[
            'op' => 'replace',
            'path' => "/plan/billing_cycles/{$sequence}/pricing_scheme/tiers",
            'value' => $tiers,
        ]]);
    }

    // Replace total_cycles for a specific billing cycle
    public static function updateTotalCycles(string $subscriptionId, int $sequence, int $totalCycles): bool
    {
        return self::updateSubscription($subscriptionId, [[
            'op' => 'replace',
            'path' => "/plan/billing_cycles/{$sequence}/total_cycles",
            'value' => $totalCycles,
        ]]);
    }

    // Replace auto_bill_outstanding (true/false)
    public static function updateAutoBillOutstanding(string $subscriptionId, bool $autoBill): bool
    {
        return self::updateSubscription($subscriptionId, [[
            'op' => 'replace',
            'path' => '/plan/payment_preferences/auto_bill_outstanding',
            'value' => $autoBill,
        ]]);
    }

    // Replace payment_failure_threshold (integer)
    public static function updatePaymentFailureThreshold(string $subscriptionId, int $threshold): bool
    {
        return self::updateSubscription($subscriptionId, [[
            'op' => 'replace',
            'path' => '/plan/payment_preferences/payment_failure_threshold',
            'value' => $threshold,
        ]]);
    }

    // Add or replace taxes.inclusive (boolean)
    public static function updateTaxesInclusive(string $subscriptionId, bool $inclusive, string $op = 'replace'): bool
    {
        return self::updateSubscription($subscriptionId, [[
            'op' => $op,
            'path' => '/plan/taxes/inclusive',
            'value' => $inclusive,
        ]]);
    }

    // Add or replace taxes.percentage (string percentage e.g. "10.00")
    public static function updateTaxesPercentage(string $subscriptionId, string $percentage, string $op = 'replace'): bool
    {
        return self::updateSubscription($subscriptionId, [[
            'op' => $op,
            'path' => '/plan/taxes/percentage',
            'value' => $percentage,
        ]]);
    }

    // Add or replace shipping_amount (array with currency_code and value)
    public static function updateShippingAmount(string $subscriptionId, array $shippingAmount, string $op = 'replace'): bool
    {
        return self::updateSubscription($subscriptionId, [[
            'op' => $op,
            'path' => '/shipping_amount',
            'value' => $shippingAmount,
        ]]);
    }

    // Replace start_time (ISO 8601 datetime string)
    public static function updateStartTime(string $subscriptionId, string $startTime): bool
    {
        return self::updateSubscription($subscriptionId, [[
            'op' => 'replace',
            'path' => '/start_time',
            'value' => $startTime,
        ]]);
    }

    // Add or replace subscriber.shipping_address (complex nested object)
    public static function updateSubscriberShippingAddress(string $subscriptionId, array $address, string $op = 'replace'): bool
    {
        return self::updateSubscription($subscriptionId, [[
            'op' => $op,
            'path' => '/subscriber/shipping_address',
            'value' => $address,
        ]]);
    }

    // Replace subscriber.payment_source (for card funded subscriptions)
    public static function updateSubscriberPaymentSource(string $subscriptionId, array $paymentSource): bool
    {
        return self::updateSubscription($subscriptionId, [[
            'op' => 'replace',
            'path' => '/subscriber/payment_source',
            'value' => $paymentSource,
        ]]);
    }

    /**
     *
    $revisionData = [
        'plan_id' => 'P-5ML4271244454362WXNWU5NQ',
        'quantity' => '2',
        'shipping_amount' => [
            'currency_code' => 'USD',
            'value' => '10.00',
        ],
        'shipping_address' => [
            'name' => [
                'full_name' => 'John Doe',
            ],
            'address' => [
                'address_line_1' => '2211 N First Street',
                'address_line_2' => 'Building 17',
                'admin_area_2' => 'San Jose',
                'admin_area_1' => 'CA',
                'postal_code' => '95131',
                'country_code' => 'US',
            ],
        ],
        'application_context' => [
            'brand_name' => 'walmart',
            'locale' => 'en-US',
            'shipping_preference' => 'SET_PROVIDED_ADDRESS',
            'payment_method' => [
                'payer_selected' => 'PAYPAL',
                'payee_preferred' => 'IMMEDIATE_PAYMENT_REQUIRED',
            ],
            'return_url' => 'https://example.com/returnUrl',
            'cancel_url' => 'https://example.com/cancelUrl',
        ],
    ];

    $response = YourClassName::reviseSubscription('I-BW452GLLEP1G', $revisionData);
     *
     *
     */
    public static function reviseSubscription(string $subscriptionId, array $data): array
    {
        $token = Client::getOauthToken();

        $response = Http::withToken($token)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->post(config('services.paypal.base_uri') . "/v1/billing/subscriptions/{$subscriptionId}/revise", $data);

        if ($response->successful()) {
            return $response->json();
        }

        Log::error('PayPal Revise Subscription Error', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        throw new \Exception('Unable to revise subscription.');
    }

    public static function updateSubscriptionStatus(string $subscriptionId, string $status, string $reason): bool
    {
        // allowed statuses - activate, cancel, suspend

        $token = Client::getOauthToken();

        $response = Http::withToken($token)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->post(config('services.paypal.base_uri') . "/v1/billing/subscriptions/{$subscriptionId}/{$status}", [
                'reason' => $reason,
            ]);

        if ($response->noContent()) {
            return true;
        }

        Log::error('PayPal Update Subscription Status Error', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        throw new \Exception('Unable to update subscription status.');
    }

    /**
     * $amount should be an array like:
     *
     * [
     *      'currency_code' => 'USD',
     *      'value' => '10.00',
     * ]
     *
     */
    public static function captureSubscriptionPayment(string $subscriptionId, string $note, string $captureType, array $amount, string $requestId): bool
    {
        $token = Client::getOauthToken();

        $response = Http::withToken($token)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'PayPal-Request-Id' => $requestId,
            ])
            ->post(config('services.paypal.base_uri') . "/v1/billing/subscriptions/{$subscriptionId}/capture", [
                'note' => $note,
                'capture_type' => $captureType,
                'amount' => $amount,
            ]);

        if ($response->status() === 202) {
            return true;
        }

        Log::error('PayPal Capture Subscription Payment Error', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        throw new \Exception('Unable to capture subscription payment.');
    }

    /**
     *
     $transactions = YourPaypalServiceClass::listSubscriptionTransactions(
            'I-BW452GLLEP1G',
            '2018-01-21T07:50:20.940Z',
            '2018-08-21T07:50:20.940Z'
        );
     */
    public static function listSubscriptionTransactions(
        string $subscriptionId,
        string $startTime,
        string $endTime,
        string $prefer = 'return=representation'
    ): array {
        $token = Client::getOauthToken();

        $query = [
            'start_time' => $startTime,
            'end_time' => $endTime,
        ];

        $response = Http::withToken($token)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Prefer' => $prefer,
            ])
            ->get(config('services.paypal.base_uri') . "/v1/billing/subscriptions/{$subscriptionId}/transactions", $query);

        if ($response->successful()) {
            return $response->json();
        }

        Log::error('PayPal List Subscription Transactions Error', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        throw new \Exception('Unable to list subscription transactions.');
    }
}
