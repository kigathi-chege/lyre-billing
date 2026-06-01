<?php

namespace Lyre\Billing\Http\Resources;

use Lyre\Billing\Models\BillableItem as BillableItemModel;
use Lyre\Resource;

class BillableItem extends Resource
{
    public function __construct(BillableItemModel $model)
    {
        parent::__construct($model);
    }
}
