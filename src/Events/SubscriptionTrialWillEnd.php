<?php

namespace Lyre\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SubscriptionTrialWillEnd
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public mixed $subscription,
        public mixed $trialEndsAt = null,
    ) {}
}
