<?php

namespace Lyre\Billing\Contracts;

interface PaymentGatewayInterface
{
    public function providerKey(): string;

    /**
     * @return array{status:string,message?:string,approval_url?:string,provider_reference?:string,order_reference?:string}
     */
    public function initiate(array $payload): array;

    /**
     * @return array{status:string,message?:string,provider_reference?:string,order_reference?:string}
     */
    public function handleCallback(array $payload): array;

    /**
     * @return array{status:string,message?:string,provider_reference?:string,order_reference?:string}
     */
    public function handleReturn(array $payload): array;

    public function isEnabled(): bool;

    public function label(): string;

    public function logo(): string;
}
