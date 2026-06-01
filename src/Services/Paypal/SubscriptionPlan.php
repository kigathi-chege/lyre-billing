<?php

namespace Lyre\Billing\Services\Paypal;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Lyre\Billing\Support\BillingSupport;

class SubscriptionPlan
{
    public static function fromSubscriptionPlan(Model $modelSubscription)
    {
        if (!PaypalModelBridge::getPlanProductId($modelSubscription)) {
            $product = Catalog::createProduct(
                BillingSupport::planDisplayName($modelSubscription),
                BillingSupport::planDisplayDescription($modelSubscription)
                    ? strip_tags(BillingSupport::planDisplayDescription($modelSubscription))
                    : BillingSupport::planDisplayName($modelSubscription),
                "SERVICE",
                'EDUCATIONAL_AND_TEXTBOOKS',
                // asset('pop-logo.svg'),
                // TODO: Kigathi - October 14 2025 - Should use logo and app url from the settings
                "https://aspirecareerconsultants.com/_app/immutable/assets/web.DG74slDU.svg",
                "https://aspirecareerconsultants.com"
            );

            PaypalModelBridge::setPlanProductId($modelSubscription, $product['id']);
            $modelSubscription->save();
        }

        $trialCycle = $modelSubscription->trial_days > 0 ? [
            "frequency" => [
                "interval_unit" => "DAY",
                "interval_count" => $modelSubscription->trial_days
            ],
            "tenure_type" => "TRIAL",
            "sequence" => 1,
            "total_cycles" => 1,
            "pricing_scheme" => [
                "fixed_price" => [
                    "value" => "0",
                    "currency_code" => "USD"
                ]
            ]
        ] : null;

        $regularCycles = [
            [
                "frequency" => [
                    "interval_unit" => match ($modelSubscription->billing_cycle) {
                        'per_day' => "DAY",
                        'per_week' => "WEEK",
                        'monthly' => "MONTH",
                        'annually' => "YEAR",
                    },
                    "interval_count" => 1
                ],
                "tenure_type" => "REGULAR",
                "sequence" => $trialCycle ? 2 : 1,
                "total_cycles" => 0,
                "pricing_scheme" => [
                    "fixed_price" => [
                        "value" => $modelSubscription->price,
                        "currency_code" => "USD"
                    ]
                ]
            ],
        ];

        if ($trialCycle) {
            array_unshift($regularCycles, $trialCycle);
        }

        $payload = [
            "product_id" => PaypalModelBridge::getPlanProductId($modelSubscription),
            "name" => $modelSubscription->name,
            "description" => $modelSubscription->description ? Str::limit(strip_tags($modelSubscription->description), 120) : $modelSubscription->name,
            // "status" => $aspireSubscription->status == 'active' ? 'ACTIVE' : "INACTIVE",
            "status" => 'ACTIVE',
            "billing_cycles" => $regularCycles,
            "payment_preferences" => [
                "auto_bill_outstanding" => true,
                "setup_fee" => [
                    "value" => "0",
                    "currency_code" => "USD"
                ],
                "setup_fee_failure_action" => "CONTINUE",
                "payment_failure_threshold" => 3
            ],
            "taxes" => [
                "percentage" => "10",
                "inclusive" => false
            ],
        ];

        try {
            $plan = self::createPaypalPlan($payload);
            Log::info("PAYPAL SUBSCRIPTION PLAN", [$plan]);
            PaypalModelBridge::setPlanId($modelSubscription, $plan['id']);
            $modelSubscription->save();
        } catch (\Exception $e) {
            report($e);
        }
    }

    public static function createPaypalPlan(array $payload)
    {
        $token = Client::getOauthToken();
        $requestId = uniqid();

        $response = Http::withToken($token)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'PayPal-Request-Id' => $requestId,
                'Prefer' => 'return=representation',
            ])
            ->post(config('services.paypal.base_uri') . config('services.paypal.subscription_uri'), $payload);

        if ($response->successful()) {
            return $response->json();
        }

        Log::error('PayPal Create Billing Plan Error', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        throw new \Exception('PayPal Plan creation failed.');
    }

    public static function listPlans(
        int $pageSize = 10,
        int $page = 1,
        bool $totalRequired = false,
        ?string $productId = null,
        string $prefer = 'return=representation'
    ): array {
        $token = Client::getOauthToken();

        $query = [
            'page_size' => $pageSize,
            'page' => $page,
            'total_required' => $totalRequired ? 'true' : 'false',
        ];

        if ($productId) {
            $query['product_id'] = $productId;
        }

        $response = Http::withToken($token)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Prefer' => $prefer,
            ])
            ->get(config('services.paypal.base_uri') . '/v1/billing/plans', $query);

        if ($response->successful()) {
            return $response->json();
        }

        Log::error('PayPal List Plans Error', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        throw new \Exception('Unable to list billing plans.');
    }

    public static function getPlanDetails(string $planId): array
    {
        $token = Client::getOauthToken();

        $response = Http::withToken($token)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->get(config('services.paypal.base_uri') . "/v1/billing/plans/{$planId}");

        if ($response->successful()) {
            return $response->json();
        }

        Log::error('PayPal Get Plan Details Error', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        throw new \Exception('Unable to retrieve billing plan details.');
    }

    public static function updatePlan(string $planId, array $operations): bool
    {
        $token = Client::getOauthToken();

        $response = Http::withToken($token)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->patch(config('services.paypal.base_uri') . "/v1/billing/plans/{$planId}", $operations);

        if ($response->noContent()) {
            return true;
        }

        Log::error('PayPal Update Billing Plan Error', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        throw new \Exception('Unable to update billing plan.');
    }

    public static function updatePlanName(string $planId, string $name): bool
    {
        return self::updatePlan($planId, [[
            'op' => 'replace',
            'path' => '/name',
            'value' => $name,
        ]]);
    }

    public static function updatePlanDescription(string $planId, string $description): bool
    {
        return self::updatePlan($planId, [[
            'op' => 'replace',
            'path' => '/description',
            'value' => $description,
        ]]);
    }

    public static function updatePlanTaxPercentage(string $planId, string $percentage): bool
    {
        return self::updatePlan($planId, [[
            'op' => 'replace',
            'path' => '/taxes/percentage',
            'value' => $percentage,
        ]]);
    }

    public static function updatePlanAutoBill(string $planId, bool $autoBillOutstanding): bool
    {
        return self::updatePlan($planId, [[
            'op' => 'replace',
            'path' => '/payment_preferences/auto_bill_outstanding',
            'value' => $autoBillOutstanding,
        ]]);
    }

    public static function updatePlanPaymentFailureThreshold(string $planId, int $threshold): bool
    {
        return self::updatePlan($planId, [[
            'op' => 'replace',
            'path' => '/payment_preferences/payment_failure_threshold',
            'value' => $threshold,
        ]]);
    }

    public static function updatePlanSetupFee(string $planId, array $fee): bool
    {
        // Example fee: ['value' => '10.00', 'currency_code' => 'USD']
        return self::updatePlan($planId, [[
            'op' => 'replace',
            'path' => '/payment_preferences/setup_fee',
            'value' => $fee,
        ]]);
    }

    public static function updatePlanSetupFeeFailureAction(string $planId, string $action): bool
    {
        // e.g., "CONTINUE" or "CANCEL"
        return self::updatePlan($planId, [[
            'op' => 'replace',
            'path' => '/payment_preferences/setup_fee_failure_action',
            'value' => $action,
        ]]);
    }

    public static function activateDeactivatePlan(string $planId, bool $activate): bool
    {
        $token = Client::getOauthToken();
        $action = $activate ? 'activate' : 'deactivate';

        $response = Http::withToken($token)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->post(config('services.paypal.base_uri') . "/v1/billing/plans/{$planId}/{$action}", []);

        if ($response->noContent()) {
            return true;
        }

        Log::error('PayPal Activate Plan Error', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        throw new \Exception('Unable to activate billing plan.');
    }

    public static function updatePlanPricing(string $planId, array $pricingSchemes): bool
    {
        $token = Client::getOauthToken();

        $response = Http::withToken($token)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->post(config('services.paypal.base_uri') . "/v1/billing/plans/{$planId}/update-pricing-schemes", [
                'pricing_schemes' => $pricingSchemes,
            ]);

        if ($response->noContent()) {
            return true;
        }

        Log::error('PayPal Update Pricing Schemes Error', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        throw new \Exception('Unable to update pricing schemes.');
    }
}
