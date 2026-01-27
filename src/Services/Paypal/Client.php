<?php

namespace Lyre\Billing\Services\Paypal;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

enum CatalogType: string
{
    case PHYSICAL = 'PHYSICAL';
    case DIGITAL = 'DIGITAL';
    case SERVICE = 'SERVICE';
}

class Client
{
    public static function getOauthToken()
    {
        if (Cache::has('paypal_access_token')) {
            return Cache::get('paypal_access_token');
        }

        $paymentMethod = \Lyre\Billing\Models\PaymentMethod::get('paypal');

        $clientId = $paymentMethod?->details['PAYPAL_CLIENT_ID'] ?? config('services.paypal.client_id');
        $secret = $paymentMethod?->details['PAYPAL_SECRET'] ?? config('services.paypal.secret');
        $baseUri = $paymentMethod?->details['PAYPAL_BASE_URI'] ?? config('services.paypal.base_uri');
        $oauthUri = $paymentMethod?->details['PAYPAL_OAUTH_URI'] ?? config('services.paypal.oauth_uri');

        $response = Http::asForm()
            ->withBasicAuth($clientId, $secret)
            ->post($baseUri . $oauthUri, [
                'grant_type' => 'client_credentials',
            ]);

        if ($response->successful()) {
            $data = $response->json();
            $expiresIn = $data['expires_in'] ?? 300;

            Cache::put('paypal_access_token', $data['access_token'], now()->addSeconds($expiresIn - 60));

            return $data['access_token'];
        }

        Log::error('PayPal OAuth Error', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        throw new \Exception('Unable to retrieve PayPal access token.');
    }

    /**
     * Helper to build a JSON Patch operation.
     *
     * @param string $op The operation: add, remove, replace, etc.
     * @param string $path The JSON pointer path (e.g., /url).
     * @param mixed $value Optional value to apply.
     * @param string|null $from Optional 'from' path (for move/copy ops).
     * @return array JSON Patch operation array.
     */
    public static function makePatchOp(string $op, string $path, $value = null, string | null $from = null): array
    {
        $operation = compact('op', 'path');

        if (in_array($op, ['add', 'replace', 'test']) && !is_null($value)) {
            $operation['value'] = $value;
        }

        if (in_array($op, ['move', 'copy']) && $from) {
            $operation['from'] = $from;
        }

        return $operation;
    }
}
