<?php

namespace Lyre\Billing\Filament\Resources\SubscriptionPlanResource\Pages;

use Lyre\Billing\Filament\Resources\SubscriptionPlanResource;
// use App\Services\Paypal\SubscriptionPlan;
use Filament\Resources\Pages\CreateRecord;

class CreateSubscriptionPlan extends CreateRecord
{
    protected static string $resource = SubscriptionPlanResource::class;

    protected function afterCreate(): void
    {
        $subscriptionPlan = $this->record;
        // SubscriptionPlan::fromAspireSubscriptionPlan($subscriptionPlan);
    }
}
