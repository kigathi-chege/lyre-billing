<?php

namespace Lyre\Billing\Repositories;

use Lyre\Repository;
use Lyre\Billing\Models\PaymentMethod;
use Lyre\Billing\Contracts\PaymentMethodRepositoryInterface;

class PaymentMethodRepository extends Repository implements PaymentMethodRepositoryInterface
{
    protected $model;

    public function __construct(PaymentMethod $model)
    {
        parent::__construct($model);
    }
}
