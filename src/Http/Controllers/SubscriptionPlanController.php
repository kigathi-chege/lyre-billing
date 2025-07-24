<?php

namespace Lyre\Billing\Http\Controllers;

use Lyre\Billing\Models\SubscriptionPlan;
use Lyre\Billing\Contracts\SubscriptionPlanRepositoryInterface;
use Lyre\Controller;

class SubscriptionPlanController extends Controller
{
    public function __construct(
        SubscriptionPlanRepositoryInterface $modelRepository
    ) {
        $model = new SubscriptionPlan();
        $modelConfig = $model->generateConfig();
        parent::__construct($modelConfig, $modelRepository);
    }

    public function subscribe(string $plan)
    {
        $plan = SubscriptionPlan::where('id', $plan)->orWhere('slug', $plan)->firstOrFail();
        return $this->modelRepository->subscribe($plan);
    }
}
