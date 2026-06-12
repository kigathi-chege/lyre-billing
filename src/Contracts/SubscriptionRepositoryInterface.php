<?php

namespace Lyre\Billing\Contracts;

use Lyre\Interface\RepositoryInterface;

interface SubscriptionRepositoryInterface extends RepositoryInterface
{
    public function approved(string $subscription);

    public function revokeRenewal(string|int $subscription);

    public function restoreRenewal(string|int $subscription);
}
