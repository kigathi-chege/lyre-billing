<?php

namespace Lyre\Billing\Filament\Resources\SubscriptionPlanResource\RelationManagers;

use Lyre\Billing\Filament\Resources\BillableResource;
use Lyre\Billing\Models\Billable;
use Filament\Forms;
use Filament\Forms\Form;
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
    function getSchema(string $resourceClass): array
    {
        $fake = new class extends \Filament\Forms\Components\Component implements \Filament\Forms\Contracts\HasForms {
            use \Filament\Forms\Concerns\InteractsWithForms;
        };

        $container = \Filament\Forms\Form::make($fake);
        return $resourceClass::form($container)->getComponents();
    }

    public function form(Form $form): Form
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
                Tables\Actions\CreateAction::make()
                    ->label('Attach Billable'),
                Tables\Actions\Action::make('createBillable')
                    ->label('Create Billable')
                    ->icon('gmdi-add-circle')
                    ->form(function () {
                        $schema = $this->getSchema(BillableResource::class);
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
                Tables\Actions\Action::make('view')
                    ->label('View')
                    ->icon('gmdi-visibility')
                    ->color('info')
                    ->url(fn($record) => BillableResource::getUrl('edit', ['record' => $record->billable_id])),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No billables attached')
            ->emptyStateDescription('Attach billables to this subscription plan to configure usage limits and pricing.')
            ->emptyStateIcon('gmdi-link-off');
    }
}
