<?php

namespace Lyre\Billing\Repositories;

use Lyre\Repository;
use Lyre\Billing\Models\Subscription;
use Lyre\Billing\Contracts\SubscriptionRepositoryInterface;
use Lyre\Billing\Services\SubscriptionLifecycleService;

class SubscriptionRepository extends Repository implements SubscriptionRepositoryInterface
{
    protected $model;

    public function __construct(Subscription $model, protected SubscriptionLifecycleService $lifecycleService)
    {
        parent::__construct($model);
    }

    public function approved(string $subscription)
    {
        $subscription = $this->lifecycleService->approveByProviderId($subscription);
        return $this->resource::make($subscription);
    }
}
