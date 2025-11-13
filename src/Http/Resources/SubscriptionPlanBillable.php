<?php

namespace Lyre\Billing\Http\Resources;

use Lyre\Billing\Models\SubscriptionPlanBillable as SubscriptionPlanBillableModel;
use Lyre\Resource;

class SubscriptionPlanBillable extends Resource
{
    public function __construct(SubscriptionPlanBillableModel $model)
    {
        parent::__construct($model);
    }
}

