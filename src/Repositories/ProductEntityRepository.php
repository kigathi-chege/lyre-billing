<?php

namespace Lyre\Billing\Repositories;

use Lyre\Repository;
use Lyre\Billing\Models\ProductEntity;
use Lyre\Billing\Contracts\ProductEntityRepositoryInterface;

class ProductEntityRepository extends Repository implements ProductEntityRepositoryInterface
{
    protected $model;

    public function __construct(ProductEntity $model)
    {
        parent::__construct($model);
    }
}
