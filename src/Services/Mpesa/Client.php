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
            ->post(config('services.mpesa.base_uri') . config('services.mpesa.oauth_uri'), [
                'grant_type' => 'client_credentials',
            ]);

        if ($response->successful()) {
            $data = $response->json();
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
}
