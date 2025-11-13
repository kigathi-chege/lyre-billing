<?php

namespace Lyre\Billing\Policies;

use Lyre\Billing\Models\SubscriptionPlanBillable;
use Lyre\Policy;

class SubscriptionPlanBillablePolicy extends Policy
{
    public function __construct(SubscriptionPlanBillable $model)
    {
        parent::__construct($model);
    }
}

