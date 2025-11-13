<?php

namespace Lyre\Billing\Repositories;

use Lyre\Repository;
use Lyre\Billing\Models\BillableUsage;
use Lyre\Billing\Contracts\BillableUsageRepositoryInterface;

class BillableUsageRepository extends Repository implements BillableUsageRepositoryInterface
{
    protected $model;

    public function __construct(BillableUsage $model)
    {
        parent::__construct($model);
    }
}

