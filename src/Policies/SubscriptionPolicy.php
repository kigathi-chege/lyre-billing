<?php

namespace Lyre\Billing\Policies;

use Lyre\Billing\Models\Subscription;
use Lyre\Policy;

class SubscriptionPolicy extends Policy
{
    public function __construct(Subscription $model)
    {
        parent::__construct($model);
    }
}
