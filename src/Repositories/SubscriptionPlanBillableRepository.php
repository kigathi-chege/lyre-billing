<?php

namespace Lyre\Billing\Repositories;

use Lyre\Repository;
use Lyre\Billing\Models\SubscriptionPlanBillable;
use Lyre\Billing\Contracts\SubscriptionPlanBillableRepositoryInterface;

class SubscriptionPlanBillableRepository extends Repository implements SubscriptionPlanBillableRepositoryInterface
{
    protected $model;

    public function __construct(SubscriptionPlanBillable $model)
    {
        parent::__construct($model);
    }
}

