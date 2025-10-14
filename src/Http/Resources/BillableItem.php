<?php

namespace App\Http\Resources;

use App\Models\BillableItem as BillableItemModel;
use Lyre\Resource;

class BillableItem extends Resource
{
    public function __construct(BillableItemModel $model)
    {
        parent::__construct($model);
    }
}
