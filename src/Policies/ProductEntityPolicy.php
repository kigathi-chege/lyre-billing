<?php

namespace Lyre\Billing\Policies;

use Lyre\Billing\Models\ProductEntity;
use Lyre\Policy;

class ProductEntityPolicy extends Policy
{
    public function __construct(ProductEntity $model)
    {
        parent::__construct($model);
    }
}
