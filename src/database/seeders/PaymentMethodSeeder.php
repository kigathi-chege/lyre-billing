<?php

namespace Lyre\Billing\Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Lyre\Billing\Models\PaymentMethod;

class PaymentMethodSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $paymentMethods = [
            [
                'name' => 'M-PESA',
                'is_default' => true,
                'details' => [
                    'MPESA_CONSUMER_KEY' => config('services.mpesa.key'),
                    'MPESA_CONSUMER_SECRET' => config('services.mpesa.secret'),
                    'MPESA_PASSKEY' => config('services.mpesa.passkey'),
                    'MPESA_BUSINESS_SHORT_CODE' => config('services.mpesa.business_short_code'),
                    'MPESA_BASE_URI' => config('services.mpesa.base_uri'),
                    'MPESA_OAUTH_URI' => config('services.mpesa.oauth_uri'),
                    'MPESA_EXPRESS_URI' => config('services.mpesa.express_uri'),
                ]
            ],
            [
                'name' => 'PayPal',
                'is_default' => false,
                'details' => [
                    'PAYPAL_CLIENT_ID' => config('services.paypal.client_id'),
                    'PAYPAL_SECRET' => config('services.paypal.secret'),
                    'PAYPAL_BASE_URI' => config('services.paypal.base_uri'),
                    'PAYPAL_OAUTH_URI' => config('services.paypal.oauth_uri'),
                    'PAYPAL_CATALOG_URI' => config('services.paypal.catalog_uri'),
                    'PAYPAL_SUBSCRIPTION_URI' => config('services.paypal.subscription_uri'),
                    'PAYPAL_RETURN_URL' => config('services.paypal.return_url'),
                    'PAYPAL_CANCEL_URL' => config('services.paypal.cancel_url'),
                ]
            ],
        ];

        foreach ($paymentMethods as $paymentMethod) {
            PaymentMethod::updateOrCreate(
                ['name' => $paymentMethod['name']],
                [...$paymentMethod]
            );
        }
    }
}
