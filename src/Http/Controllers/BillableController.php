<?php

namespace App\Http\Controllers;

use App\Models\Billable;
use App\Repositories\Interface\BillableRepositoryInterface;
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
