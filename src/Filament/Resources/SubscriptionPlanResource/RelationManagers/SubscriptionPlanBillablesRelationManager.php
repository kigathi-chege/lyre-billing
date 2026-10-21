<?php

namespace Lyre\Billing\Filament\Resources\SubscriptionPlanResource\RelationManagers;

use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Forms\Components\Component;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Lyre\Billing\Filament\Resources\BillableResource;
use Lyre\Billing\Models\Billable;
use Lyre\Billing\Support\BillingRules;

class SubscriptionPlanBillablesRelationManager extends RelationManager
{
    protected static string $relationship = 'subscriptionPlanBillables';

    protected static ?string $title = 'Billables';

    protected static ?string $recordTitleAttribute = 'billable.name';

    // TODO: Kigathi - June 12 2025 - Understand this function, and extract it for reusability
    protected function getResourceSchemaComponents(string $resourceClass): array
    {
        $fake = new class extends Component implements HasForms
        {
            use InteractsWithForms;
        };

        $container = Schema::make($fake);

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
                Forms\Components\TextInput::make('usage_limit')
                    ->label('Limit on this plan')
                    ->numeric()
                    ->minValue(0)
                    ->helperText('Picks for "any" content, or the allowance per period for a meter. Empty = the bundle default (unlimited when unset).'),
                Forms\Components\TextInput::make('unit_price')
                    ->label('Overage price per unit')
                    ->numeric()
                    ->minValue(0),
                Forms\Components\Select::make('metadata.on_exhaustion')
                    ->label('When used up')
                    ->options(['upsell' => 'Block and offer an upgrade', 'overage' => 'Charge overage'])
                    ->placeholder('Bundle default'),
                Forms\Components\TextInput::make('metadata.per_resource_limit')
                    ->label('Limit per item (e.g. attempts per assessment)')
                    ->numeric()
                    ->minValue(0),
                Forms\Components\Select::make('metadata.reset_period')
                    ->label('Resets')
                    ->options(array_combine(BillingRules::RESET_PERIODS, array_map(fn ($period) => Str::headline($period), BillingRules::RESET_PERIODS)))
                    ->placeholder('Bundle default'),
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
                Tables\Columns\TextColumn::make('usage_limit')
                    ->label('Limit')
                    ->placeholder('Default'),
                Tables\Columns\TextColumn::make('unit_price')
                    ->label('Overage')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('metadata.on_exhaustion')
                    ->label('When used up')
                    ->badge()
                    ->placeholder('Default'),
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
                CreateAction::make()
                    ->label('Attach Billable'),
                Action::make('createBillable')
                    ->label('Create Billable')
                    ->icon('gmdi-add-circle')
                    ->schema(function () {
                        $schema = $this->getResourceSchemaComponents(BillableResource::class);
                        $schema = array_filter($schema, fn ($field) => $field->getName() !== 'user_id');

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
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->icon('gmdi-visibility')
                    ->color('info')
                    ->url(fn ($record) => BillableResource::getUrl('edit', ['record' => $record->billable_id])),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No billables attached')
            ->emptyStateDescription('Attach billables to this subscription plan to configure usage limits and pricing.')
            ->emptyStateIcon('gmdi-link-off');
    }
}
