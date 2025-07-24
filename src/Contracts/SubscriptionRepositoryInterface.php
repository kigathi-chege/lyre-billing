<?php

namespace Lyre\Billing\Contracts;

use Lyre\Interface\RepositoryInterface;

interface SubscriptionRepositoryInterface extends RepositoryInterface
{
    public function approved(string $subscription);
}
