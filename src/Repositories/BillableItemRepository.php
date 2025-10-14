<?php

namespace App\Repositories;

use Lyre\Repository;
use App\Models\BillableItem;
use App\Repositories\Interface\BillableItemRepositoryInterface;

class BillableItemRepository extends Repository implements BillableItemRepositoryInterface
{
    protected $model;

    public function __construct(BillableItem $model)
    {
        parent::__construct($model);
    }
}
