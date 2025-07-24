<?php

namespace Lyre\Billing\Policies;

use Lyre\Billing\Models\Product;
use Lyre\Policy;

class ProductPolicy extends Policy
{
    public function __construct(Product $model)
    {
        parent::__construct($model);
    }
}
