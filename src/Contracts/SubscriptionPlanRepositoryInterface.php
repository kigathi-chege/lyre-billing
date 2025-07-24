<?php

namespace Lyre\Billing\Contracts;

use Lyre\Billing\Models\SubscriptionPlan;
use Lyre\Interface\RepositoryInterface;

interface SubscriptionPlanRepositoryInterface extends RepositoryInterface
{
    public function subscribe(SubscriptionPlan $plan);
}
