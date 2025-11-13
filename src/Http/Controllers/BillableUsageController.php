<?php

namespace Lyre\Billing\Http\Controllers;

use Lyre\Billing\Models\BillableUsage;
use Lyre\Billing\Contracts\BillableUsageRepositoryInterface;
use Lyre\Controller;

class BillableUsageController extends Controller
{
    public function __construct(
        BillableUsageRepositoryInterface $modelRepository
    ) {
        $model = new BillableUsage();
        $modelConfig = $model->generateConfig();
        parent::__construct($modelConfig, $modelRepository);
    }
}

