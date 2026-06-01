<?php

namespace Lyre\Billing\Http\Resources;

use Lyre\Billing\Models\Billable as BillableModel;
use Lyre\Resource;

class Billable extends Resource
{
    public function __construct(BillableModel $model)
    {
        parent::__construct($model);
    }
}
