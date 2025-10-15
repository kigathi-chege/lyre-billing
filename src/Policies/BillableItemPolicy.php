<?php

namespace Lyre\Billing\Policies;

use Lyre\Billing\Models\BillableItem;
use Lyre\Policy;

class BillableItemPolicy extends Policy
{
    public function __construct(BillableItem $model)
    {
        parent::__construct($model);
    }
}
