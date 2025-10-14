<?php

namespace App\Policies;

use App\Models\Billable;
use Lyre\Policy;

class BillablePolicy extends Policy
{
    public function __construct(Billable $model)
    {
        parent::__construct($model);
    }
}
