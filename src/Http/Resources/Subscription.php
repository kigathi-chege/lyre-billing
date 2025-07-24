<?php

namespace Lyre\Billing\Http\Resources;

use Lyre\Billing\Models\Subscription as SubscriptionModel;
use Lyre\Resource;

class Subscription extends Resource
{
    public function __construct(SubscriptionModel $model)
    {
        parent::__construct($model);
    }
}
