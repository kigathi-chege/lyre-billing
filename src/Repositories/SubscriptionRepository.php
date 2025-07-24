<?php

namespace Lyre\Billing\Repositories;

use Lyre\Jobs\SendEmails;
use Lyre\Repository;
use Lyre\Billing\Models\Subscription;
use Lyre\Billing\Contracts\SubscriptionRepositoryInterface;

class SubscriptionRepository extends Repository implements SubscriptionRepositoryInterface
{
    protected $model;

    public function __construct(Subscription $model)
    {
        parent::__construct($model);
    }

    public function approved(string $subscription)
    {
        $subscription = $this->model->where('paypal_id', $subscription)->firstOrFail();
        $subscription->update(['status' => 'active']);
        SendEmails::dispatch(
            email: $subscription->user->email,
            subject: 'Subscription Activated',
            view: 'email.subscriptions.activated',
            data: [
                'name' => $subscription->user->name,
                'buttonText' => 'Log In',
                'buttonLink' => config('app.client_url') . '/login'
            ]
        );
        return $this->resource::make($subscription);
    }
}
