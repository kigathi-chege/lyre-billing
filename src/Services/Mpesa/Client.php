<?php

namespace Lyre\Billing\Services\Mpesa;

use Illuminate\Support\Facades\Http;

class Client
{
    public static function getOauthToken()
    {
        if (cache()->has('mpesa_access_token')) {
            return cache()->get('mpesa_access_token');
        }

        $response = Http::asForm()
            ->withBasicAuth(config('services.mpesa.key'), config('services.mpesa.secret'))
            ->withHeaders([
                'Content-Type' => 'application/json',
            ])
            ->get(config('services.mpesa.base_uri') . config('services.mpesa.oauth_uri'), [
                'grant_type' => 'client_credentials',
            ]);

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

    public static function express()
    {
        $token = self::getOauthToken();

        $response = Http::withToken($token)
            ->withHeaders([
                'Content-Type' => 'application/json',
            ])
            ->post(config('services.mpesa.base_uri') . config('services.mpesa.express_uri'), [
                "BusinessShortCode" => 174379, // The number identifying the organization
                "Password" => "MTc0Mzc5YmZiMjc5ZjlhYTliZGJjZjE1OGU5N2RkNzFhNDY3Y2QyZTBjODkzMDU5YjEwZjc4ZTZiNzJhZGExZWQyYzkxOTIwMjUxMDE0MTUxNTE2",
                "Timestamp" => "20251014151516",
                "TransactionType" => "CustomerPayBillOnline", // for Paybill Numbers is `CustomerPayBillOnline`, for Till Numbers is `CustomerBuyGoodsOnline`
                "Amount" => 1,
                "PartyA" => 254104752008, // The phone number sending money
                "PartyB" => 174379, // The organization receiving the money
                "PhoneNumber" => 254707699590, // The phone receiving the STK push
                "CallBackURL" => config('app.webhook') . "/mpesa",
                "AccountReference" => "CompanyXLTD", // Value displayed to customer in the STK Pin Prompt along with Business Name
                "TransactionDesc" => "Payment of X"
            ]);

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
        logger()->info('Mpesa Webhook', $data);

        return __response(
            true,
            "Webhook",
            $data,
            get_response_code("webhook")
        );
    }
}
