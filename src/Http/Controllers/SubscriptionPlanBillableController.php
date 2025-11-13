<?php

namespace Lyre\Billing\Http\Controllers;

use Lyre\Billing\Models\SubscriptionPlanBillable;
use Lyre\Billing\Contracts\SubscriptionPlanBillableRepositoryInterface;
use Lyre\Controller;

class SubscriptionPlanBillableController extends Controller
{
    public function __construct(
        SubscriptionPlanBillableRepositoryInterface $modelRepository
    ) {
        $model = new SubscriptionPlanBillable();
        $modelConfig = $model->generateConfig();
        parent::__construct($modelConfig, $modelRepository);
    }
}

