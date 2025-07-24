<?php

namespace Lyre\Billing\Http\Resources;

use Lyre\Billing\Models\ProductEntity as ProductEntityModel;
use Lyre\Resource;

class ProductEntity extends Resource
{
    public function __construct(ProductEntityModel $model)
    {
        parent::__construct($model);
    }
}
