<?php

namespace Lyre\Billing\Policies;

use Lyre\Billing\Models\Billable;
use Lyre\Policy;

class BillablePolicy extends Policy
{
    public function __construct(Billable $model)
    {
        parent::__construct($model);
    }
}
