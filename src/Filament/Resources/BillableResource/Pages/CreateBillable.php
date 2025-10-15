<?php

namespace Lyre\Billing\Filament\Resources\BillableResource\Pages;

use Lyre\Billing\Filament\Resources\BillableResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBillable extends CreateRecord
{
    protected static string $resource = BillableResource::class;
}
