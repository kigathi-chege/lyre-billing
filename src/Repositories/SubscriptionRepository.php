<?php

namespace Lyre\Billing\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Lyre\Exceptions\CommonException;
use Lyre\Repository;
use Lyre\Billing\Models\Subscription;
use Lyre\Billing\Contracts\SubscriptionRepositoryInterface;
use Lyre\Billing\Services\SubscriptionLifecycleService;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

    public function getQuery()
    {
        $user = auth()->user();
        $viewAnyPermission = get_model_permission_by_prefix(Subscription::class, 'view-any');

        if ($user && $user->can($viewAnyPermission)) {
            return $this->model->newQueryWithoutScopes();
        }

        return parent::getQuery();
    }

    public function revokeRenewal(string|int $subscription)
    {
        $subscription = $this->resolveOwnedSubscription($subscription);

        if (! $subscription->auto_renew) {
            return $this->resource::make($subscription);
        }

        if (! $subscription->is_access_active) {
            throw new HttpException(422, 'Only active subscriptions can revoke renewal.');
        }

        if (! $subscription->is_renewable) {
            throw new HttpException(422, 'This subscription can no longer be changed.');
        }

        $subscription->update(['auto_renew' => false]);

        return $this->resource::make($subscription->fresh());
    }

    public function restoreRenewal(string|int $subscription)
    {
        $subscription = $this->resolveOwnedSubscription($subscription);

        if ($subscription->auto_renew) {
            return $this->resource::make($subscription);
        }

        if (! $subscription->is_access_active) {
            throw new HttpException(422, 'Only active subscriptions can restore renewal.');
        }

        $subscription->update(['auto_renew' => true]);

        return $this->resource::make($subscription->fresh());
    }

    protected function resolveOwnedSubscription(string|int $subscription): Subscription
    {
        $user = auth()->user();

        if (! $user || (method_exists($user, 'isGuest') && $user->isGuest())) {
            throw new HttpException(403, 'Unauthorized');
        }

        $record = $this->model->query()
            ->where('user_id', $user->getAuthIdentifier())
            ->where(function (Builder $query) use ($subscription) {
                $query->where('slug', (string) $subscription);

                if (is_numeric($subscription)) {
                    $query->orWhere('id', (int) $subscription);
                }
            })
            ->first();

        if (! $record) {
            throw CommonException::fromMessage('Subscription not found for this user.');
        }

        return $record;
    }
}
