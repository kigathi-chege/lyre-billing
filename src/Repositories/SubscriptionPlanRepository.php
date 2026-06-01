<?php

namespace Lyre\Billing\Repositories;

use Lyre\Billing\Http\Resources\SubscriptionPlan as ResourcesSubscriptionPlan;
use Lyre\Repository;
use Lyre\Billing\Models\SubscriptionPlan;
use Lyre\Billing\Contracts\SubscriptionPlanRepositoryInterface;
use Lyre\Billing\Services\PlanSubscriptionService;
use Lyre\Exceptions\CommonException;

class SubscriptionPlanRepository extends Repository implements SubscriptionPlanRepositoryInterface
{
    protected $model;

    public function __construct(SubscriptionPlan $model, protected PlanSubscriptionService $planSubscriptionService)
    {
        parent::__construct($model);
    }

    public function subscribe(SubscriptionPlan $plan, ?string $provider = null)
    {
        try {
            $result = $this->planSubscriptionService->subscribeToPlan($plan, provider: $provider);
        } catch (CommonException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw CommonException::fromMessage($exception->getMessage(), 500);
        }

        return ResourcesSubscriptionPlan::make($plan)->additional(['links' => $result['links'] ?? []]);
    }
}
