<?php

namespace Lyre\Billing\Policies;

use Lyre\Billing\Models\SubscriptionPlan;
use Lyre\Policy;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;

class SubscriptionPlanPolicy extends Policy
{
    public function __construct(SubscriptionPlan $model)
    {
        parent::__construct($model);
    }

    public function viewAny(?Authenticatable $user): Response
    {
        return Response::allow();
    }

    public function view(?Authenticatable $user, $model): Response
    {
        return Response::allow();
    }
}
