<?php

namespace Lyre\Billing\Policies;

use Lyre\Billing\Models\SubscriptionPlan;
use App\Models\User;
use Lyre\Policy;
use Illuminate\Auth\Access\Response;

class SubscriptionPlanPolicy extends Policy
{
    public function __construct(SubscriptionPlan $model)
    {
        parent::__construct($model);
    }

    public function viewAny(?User $user): Response
    {
        return Response::allow();
    }

    public function view(?User $user, $model): Response
    {
        return Response::allow();
    }
}
