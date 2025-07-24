<?php

namespace Lyre\Billing\Http\Resources;

use Lyre\Billing\Models\SubscriptionProduct as SubscriptionProductModel;
use Lyre\Resource;

class SubscriptionProduct extends Resource
{
    public function __construct(SubscriptionProductModel $model)
    {
        parent::__construct($model);
    }
}
