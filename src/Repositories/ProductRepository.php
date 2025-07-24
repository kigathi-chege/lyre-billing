<?php

namespace Lyre\Billing\Repositories;

use Lyre\Repository;
use Lyre\Billing\Models\Product;
use Lyre\Billing\Contracts\ProductRepositoryInterface;

class ProductRepository extends Repository implements ProductRepositoryInterface
{
    protected $model;

    public function __construct(Product $model)
    {
        parent::__construct($model);
    }
}
