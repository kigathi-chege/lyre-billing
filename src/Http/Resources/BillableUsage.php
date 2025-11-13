<?php

namespace Lyre\Billing\Http\Resources;

use Lyre\Billing\Models\BillableUsage as BillableUsageModel;
use Lyre\Resource;

class BillableUsage extends Resource
{
    public function __construct(BillableUsageModel $model)
    {
        parent::__construct($model);
    }
}

