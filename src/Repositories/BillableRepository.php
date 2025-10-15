<?php

namespace Lyre\Billing\Repositories;

use Lyre\Repository;
use Lyre\Billing\Models\Billable;
use Lyre\Billing\Contracts\BillableRepositoryInterface;

class BillableRepository extends Repository implements BillableRepositoryInterface
{
    protected $model;

    public function __construct(Billable $model)
    {
        parent::__construct($model);
    }
}
