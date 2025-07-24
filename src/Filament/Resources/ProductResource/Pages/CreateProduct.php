<?php

namespace Lyre\Billing\Filament\Resources\ProductResource\Pages;

use Lyre\Billing\Filament\Resources\ProductResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;
}
