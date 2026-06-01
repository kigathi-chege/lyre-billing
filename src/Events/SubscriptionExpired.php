<?php

namespace Lyre\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SubscriptionExpired
{
    use Dispatchable, SerializesModels;

    public function __construct(public mixed $subscription) {}
}
