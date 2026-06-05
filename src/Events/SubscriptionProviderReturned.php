<?php

namespace Lyre\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SubscriptionProviderReturned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public mixed $subscription,
        public string $provider,
        public string $state,
        public array $payload = [],
    ) {}
}
