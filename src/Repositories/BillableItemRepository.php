<?php

namespace Lyre\Billing\Repositories;

use Lyre\Repository;
use Lyre\Billing\Models\BillableItem;
use Lyre\Billing\Contracts\BillableItemRepositoryInterface;

class BillableItemRepository extends Repository implements BillableItemRepositoryInterface
{
    protected $model;

    public function __construct(BillableItem $model)
    {
        parent::__construct($model);
    }
}
