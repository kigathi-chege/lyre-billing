<?php

namespace Lyre\Billing\Http\Controllers;

use Illuminate\Http\Request;
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

    public function subscribe(Request $request, string $plan)
    {
        $plan = SubscriptionPlan::query()
            ->where('slug', $plan)
            ->when(ctype_digit($plan), function ($query) use ($plan) {
                $query->orWhere('id', (int) $plan);
            })
            ->firstOrFail();

        return $this->modelRepository->subscribe($plan, $request->query('provider'));
    }
}
