<?php

namespace Lyre\Billing\Http\Controllers;

use Lyre\Billing\Contracts\BillableRepositoryInterface;
use Lyre\Billing\Models\Billable;
use Lyre\Controller;

class BillableController extends Controller
{
    public function __construct(
        BillableRepositoryInterface $modelRepository
    ) {
        $model = new Billable();
        $modelConfig = $model->generateConfig();
        parent::__construct($modelConfig, $modelRepository);
    }
}
