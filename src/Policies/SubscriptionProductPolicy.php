<?php

namespace Lyre\Billing\Policies;

use Lyre\Billing\Models\SubscriptionProduct;
use Lyre\Policy;

class SubscriptionProductPolicy extends Policy
{
    public function __construct(SubscriptionProduct $model)
    {
        parent::__construct($model);
    }
}
