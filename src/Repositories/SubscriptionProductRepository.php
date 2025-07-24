<?php

namespace Lyre\Billing\Repositories;

use Lyre\Repository;
use Lyre\Billing\Models\SubscriptionProduct;
use Lyre\Billing\Contracts\SubscriptionProductRepositoryInterface;

class SubscriptionProductRepository extends Repository implements SubscriptionProductRepositoryInterface
{
    protected $model;

    public function __construct(SubscriptionProduct $model)
    {
        parent::__construct($model);
    }
}
