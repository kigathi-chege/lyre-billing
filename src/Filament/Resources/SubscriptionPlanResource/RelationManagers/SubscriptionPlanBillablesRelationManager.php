<?php

namespace Lyre\Billing\Filament\Resources\SubscriptionPlanResource\RelationManagers;

use Lyre\Billing\Filament\Resources\BillableResource;
use Lyre\Billing\Models\Billable;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use FilamentTiptapEditor\TiptapEditor;

class SubscriptionPlanBillablesRelationManager extends RelationManager
{
    protected static string $relationship = 'subscriptionPlanBillables';

    protected static ?string $title = 'Billables';

    protected static ?string $recordTitleAttribute = 'billable.name';

    // TODO: Kigathi - June 12 2025 - Understand this function, and extract it for reusability
    protected function getResourceSchemaComponents(string $resourceClass): array
    {
        $fake = new class extends \Filament\Forms\Components\Component implements \Filament\Forms\Contracts\HasForms {
            use \Filament\Forms\Concerns\InteractsWithForms;
        };

        $container = \Filament\Schemas\Schema::make($fake);
        return $resourceClass::form($container)->getComponents();
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Forms\Components\Select::make('billable_id')
                    ->label('Billable')
                    ->relationship('billable', 'name')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->helperText('Select the billable item to associate with this subscription plan'),
                Forms\Components\TextInput::make('order')
                    ->label('Order')
                    ->numeric()
                    ->default(function () {
                        $maxOrder = $this->getOwnerRecord()
                            ->subscriptionPlanBillables()
                            ->max('order') ?? 0;
                        return $maxOrder + 1;
                    })
                    ->required()
                    ->helperText('Order in which this billable appears'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('billable.name')
            ->defaultSort('order', 'asc')
            ->columns([
                Tables\Columns\TextColumn::make('order')
                    ->label('Order')
                    ->numeric()
                    ->sortable()
                    ->width('80px'),
                Tables\Columns\TextColumn::make('billable.name')
                    ->label('Billable')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                Tables\Columns\TextColumn::make('billable.status')
                    ->label('Billable Status')
                    ->badge()
                    ->colors([
                        'success' => 'active',
                        'danger' => 'inactive',
                    ])
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('billable_id')
                    ->label('Billable')
                    ->relationship('billable', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->headerActions([
                \Filament\Actions\CreateAction::make()
                    ->label('Attach Billable'),
                \Filament\Actions\Action::make('createBillable')
                    ->label('Create Billable')
                    ->icon('gmdi-add-circle')
                    ->form(function () {
                        $schema = $this->getResourceSchemaComponents(BillableResource::class);
                        $schema = array_filter($schema, fn($field) => $field->getName() !== 'user_id');
                        return $schema;
                    })
                    ->action(function (array $data) {
                        // Create the billable
                        $billable = Billable::create($data);

                        // Get the max order for this subscription plan
                        $maxOrder = $this->getOwnerRecord()
                            ->subscriptionPlanBillables()
                            ->max('order') ?? 0;

                        // Attach it to the subscription plan with order
                        $this->getOwnerRecord()->subscriptionPlanBillables()->create([
                            'billable_id' => $billable->id,
                            'order' => $maxOrder + 1,
                        ]);
                    })
                    ->successNotificationTitle('Billable created and attached successfully'),
            ])
            ->actions([
                \Filament\Actions\Action::make('view')
                    ->label('View')
                    ->icon('gmdi-visibility')
                    ->color('info')
                    ->url(fn($record) => BillableResource::getUrl('edit', ['record' => $record->billable_id])),
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No billables attached')
            ->emptyStateDescription('Attach billables to this subscription plan to configure usage limits and pricing.')
            ->emptyStateIcon('gmdi-link-off');
    }
}
