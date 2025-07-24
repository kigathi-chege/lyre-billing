<?php

namespace Lyre\Billing\Filament\Resources\SubscriptionResource\Pages;

use Lyre\Billing\Filament\Resources\SubscriptionResource;
use Lyre\Billing\Models\Invoice;
// use App\Services\Paypal\Subscription;
use Filament\Resources\Pages\CreateRecord;

class CreateSubscription extends CreateRecord
{
    protected static string $resource = SubscriptionResource::class;

    protected function afterCreate(): void
    {
        $subscription = $this->record;

        $invoice = Invoice::create([
            'amount' => $subscription->subscriptionPlan->price,
            'subscription_id' => $subscription->id
        ]);

        // $links = Subscription::fromAspireSubscription($subscription, $invoice->invoice_number);
        // NOTE: Kigathi - June 2 2025 - This will return a list of links for paypal approval, retrieval, or edit
    }
}
