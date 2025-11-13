<?php

namespace Lyre\Billing\Filament\Resources\SubscriptionPlanBillableResource\Pages;

use Lyre\Billing\Filament\Resources\SubscriptionPlanBillableResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSubscriptionPlanBillables extends ListRecords
{
    protected static string $resource = SubscriptionPlanBillableResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

