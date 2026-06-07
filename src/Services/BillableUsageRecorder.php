<?php

namespace Lyre\Billing\Services;

use Illuminate\Database\Eloquent\Model;
use Lyre\Billing\Models\BillableUsage;

class BillableUsageRecorder
{
    public function record(Model $billableItem, Model $user, float $quantity, float $amount = 0, ?Model $subscription = null, array $metadata = []): BillableUsage
    {
        return BillableUsage::create([
            'billable_item_id' => $billableItem->getKey(),
            'subscription_id' => $subscription?->getKey(),
            'user_id' => $user->getKey(),
            'quantity' => $quantity,
            'amount' => $amount,
            'recorded_at' => now(),
            'metadata' => $metadata,
        ]);
    }
}
