<?php

namespace Lyre\Billing\Filament\Resources\BillableResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class BillableItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'billableItems';

    /**
     * Update metadata field with billable method information
     */
    protected function updateMetadata(string $class, callable $set): void
    {
        $metadata = collect(get_billable_methods())
            ->firstWhere('class', $class);

        if ($metadata) {
            $set('metadata', $metadata);
        } else {
            $set('metadata', null);
        }
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->maxLength(255)
                    ->nullable(),
                Forms\Components\Select::make('pricing_model')
                    ->required()
                    ->options([
                        'free' => 'Free',
                        // 'fixed' => 'Fixed',
                        'quota_based' => 'Quota Based',
                        'usage_based' => 'Usage Based',
                    ])
                    ->default('free')
                    ->reactive(),
                Forms\Components\TextInput::make('usage_limit')
                    ->label('Usage Limit')
                    ->numeric()
                    ->nullable()
                    ->visible(fn(callable $get) => $get('pricing_model') === 'quota_based'),
                Forms\Components\TextInput::make('unit_price')
                    ->label('Unit Price')
                    ->numeric()
                    ->nullable()
                    ->visible(fn(callable $get) => $get('pricing_model') === 'usage_based'),
                Forms\Components\TextInput::make('currency')
                    ->label('Currency')
                    ->maxLength(3)
                    ->nullable()
                    ->visible(fn(callable $get) => $get('pricing_model') === 'usage_based'),
                Forms\Components\Select::make('status')
                    ->required()
                    ->options([
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                    ])
                    ->default('active'),
                Forms\Components\Select::make('item_type')
                    ->label('Item Type')
                    ->options(fn() => [
                        'model' => 'Model',
                        'function' => 'Function'
                    ])
                    ->searchable()
                    ->reactive()
                    ->afterStateUpdated(function ($state, callable $set) {
                        // Clear item_id and metadata when item_type changes
                        $set('item_id', null);
                        $set('metadata', null);
                    })
                    ->nullable(),
                Forms\Components\Select::make('item_id')
                    ->label('Item ID')
                    ->options(function (callable $get) {
                        $itemType = $get('item_type');

                        if ($itemType == 'function') {
                            return collect(get_billable_methods())
                                ->mapWithKeys(fn($value) => [$value['class'] => $value['args'][0]])
                                ->all();
                        }

                        return collect(get_model_classes())
                            ->mapWithKeys(fn($value, $key) => [$value => $key])
                            ->all();
                    })
                    ->searchable()
                    ->reactive()
                    ->afterStateUpdated(function ($state, callable $get, callable $set) {
                        $itemType = $get('item_type');

                        if ($itemType === 'function' && $state) {
                            $this->updateMetadata($state, $set);
                        } else {
                            $set('metadata', null);
                        }
                    })
                    ->nullable(),
                Forms\Components\Hidden::make('metadata'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('pricing_model')
                    ->badge()
                    ->colors([
                        'success' => 'free',
                        'warning' => 'fixed',
                        'info' => 'usage_based',
                    ]),
                Tables\Columns\TextColumn::make('item_type')
                    ->label('Item Type')
                    ->badge()
                    ->colors([
                        'success' => 'model',
                        'info' => 'function',
                    ])
                    ->toggleable(),
                Tables\Columns\TextColumn::make('item_id')
                    ->label('Item ID')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('usage_limit')
                    ->label('Usage Limit')
                    ->numeric()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('unit_price')
                    ->label('Unit Price')
                    ->numeric()
                    ->money(fn($record) => $record->currency ?? 'USD')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'success' => 'active',
                        'danger' => 'inactive',
                    ]),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('pricing_model')
                    ->options([
                        'free' => 'Free',
                        'fixed' => 'Fixed',
                        'usage_based' => 'Usage Based',
                    ]),
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                    ]),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
