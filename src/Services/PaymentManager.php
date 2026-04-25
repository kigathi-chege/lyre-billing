<?php

namespace Lyre\Billing\Services;

use InvalidArgumentException;
use Lyre\Billing\Contracts\PaymentGatewayInterface;
use Lyre\Billing\Services\Gateways\MpesaGateway;
use Lyre\Billing\Services\Gateways\PaypalGateway;
use Lyre\Billing\Services\Gateways\PaystackGateway;
use Lyre\Billing\Services\Gateways\StripeGateway;

class PaymentManager
{
    /**
     * @var array<string, PaymentGatewayInterface>
     */
    private array $gateways;

    public function __construct(
        MpesaGateway $mpesa,
        PaypalGateway $paypal,
        StripeGateway $stripe,
        PaystackGateway $paystack,
    ) {
        $this->gateways = [
            $mpesa->providerKey() => $mpesa,
            $paypal->providerKey() => $paypal,
            $stripe->providerKey() => $stripe,
            $paystack->providerKey() => $paystack,
        ];
    }

    public function initiate(string $provider, string $orderReference, array $payload = [], ?int $userId = null): array
    {
        $gateway = $this->resolve($provider);

        return $gateway->initiate([
            ...$payload,
            'order_reference' => $orderReference,
            'user_id' => $userId,
        ]);
    }

    public function handleCallback(string $provider, array $payload): array
    {
        return $this->resolve($provider)->handleCallback($payload);
    }

    public function handleReturn(string $provider, array $payload): array
    {
        return $this->resolve($provider)->handleReturn($payload);
    }

    public function availableProviders(): array
    {
        $providers = collect($this->gateways)
            ->map(fn (PaymentGatewayInterface $gateway) => [
                'key' => $gateway->providerKey(),
                'label' => $gateway->label(),
                'enabled' => $gateway->isEnabled(),
                'available' => $gateway->isEnabled(),
                'logo' => $gateway->logo(),
                'is_default' => false,
            ])
            ->values();

        $defaultKey = $providers->first(fn (array $provider) => $provider['key'] === 'mpesa' && $provider['available'])['key']
            ?? $providers->where('available', true)->first()['key']
            ?? $providers->first()['key']
            ?? null;

        return $providers
            ->map(function (array $provider) use ($defaultKey) {
                $provider['is_default'] = $provider['key'] === $defaultKey;
                return $provider;
            })
            ->all();
    }

    private function resolve(string $provider): PaymentGatewayInterface
    {
        $provider = strtolower($provider);

        if (!isset($this->gateways[$provider])) {
            throw new InvalidArgumentException("Unsupported payment provider [{$provider}].");
        }

        return $this->gateways[$provider];
    }
}
