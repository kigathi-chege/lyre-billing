<?php

namespace Lyre\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SubscriptionProcessStarted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public mixed $subscription,
        public mixed $plan = null,
        public mixed $invoice = null,
        public ?string $provider = null,
    ) {}
}
