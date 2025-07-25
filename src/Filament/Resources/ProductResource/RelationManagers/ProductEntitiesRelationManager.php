<?php

namespace Lyre\Billing\Filament\Resources\ProductResource\RelationManagers;

use Filament\Forms\Form;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Actions\Action;

class ProductEntitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'productEntities';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('entity_type')
                // ->options([
                //     \App\Models\Exam::class => 'Exam',
                //     \App\Models\Course::class => 'Course',
                // ])
                // ->default(\App\Models\Exam::class)
                ->searchable()
                ->reactive()
                ->afterStateUpdated(fn(callable $set) => $set('entity_id', null)),
            Forms\Components\Select::make('entity_id')
                ->label('Entity')
                ->helperText('Please choose a type first')
                ->required()
                ->options(function (callable $get) {
                    $productType = $get('entity_type');

                    if (!$productType || !class_exists($productType)) {
                        return [];
                    }

                    // Customize pluck columns if needed, here assuming 'name' & 'id'
                    return $productType::query()
                        ->pluck('name', 'id')
                        ->toArray();
                })
                ->searchable()
                ->reactive()
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('entity_type')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('entity.name')
                    ->numeric()
                    ->sortable(),
            ])
            ->filters([])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Action::make('view')
                    ->label('View')
                    ->icon('gmdi-visibility')
                    ->color('info')
                    ->url(fn($record) => route('filament.admin.resources.assessments.edit', $record->id)),
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
