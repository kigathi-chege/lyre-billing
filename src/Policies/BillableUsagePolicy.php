<?php

namespace Lyre\Billing\Policies;

use Lyre\Billing\Models\BillableUsage;
use Lyre\Policy;

class BillableUsagePolicy extends Policy
{
    public function __construct(BillableUsage $model)
    {
        parent::__construct($model);
    }
}

