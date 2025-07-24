<?php

namespace Lyre\Billing\Http\Resources;

use Lyre\Billing\Models\SubscriptionPlan as SubscriptionPlanModel;
use Lyre\Resource;

class SubscriptionPlan extends Resource
{
    public function __construct(SubscriptionPlanModel $model)
    {
        parent::__construct($model);
    }
}
