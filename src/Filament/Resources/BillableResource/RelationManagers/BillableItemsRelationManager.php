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
                        'fixed' => 'Fixed',
                        'usage_based' => 'Usage Based',
                    ])
                    ->default('free'),
                Forms\Components\Select::make('status')
                    ->required()
                    ->options([
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                    ])
                    ->default('active'),
                Forms\Components\TextInput::make('item_type')
                    ->label('Item Type (Model Class)')
                    ->helperText('e.g., App\Models\Product')
                    ->maxLength(255)
                    ->nullable(),
                Forms\Components\TextInput::make('item_id')
                    ->label('Item ID')
                    ->numeric()
                    ->nullable(),
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
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'success' => 'active',
                        'danger' => 'inactive',
                    ]),
                Tables\Columns\TextColumn::make('item_type')
                    ->label('Item Type')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('item_id')
                    ->label('Item ID')
                    ->toggleable(),
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
