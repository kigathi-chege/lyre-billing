<?php

namespace Lyre\Billing\Http\Resources;

use Lyre\Billing\Models\Product as ProductModel;
use Lyre\Resource;

class Product extends Resource
{
    public function __construct(ProductModel $model)
    {
        parent::__construct($model);
    }
}
