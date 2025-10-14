<?php

namespace App\Repositories;

use Lyre\Repository;
use App\Models\Billable;
use App\Repositories\Interface\BillableRepositoryInterface;

class BillableRepository extends Repository implements BillableRepositoryInterface
{
    protected $model;

    public function __construct(Billable $model)
    {
        parent::__construct($model);
    }
}
