<?php

namespace Lyre\Billing\Filament\Resources;

use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Lyre\Billing\Filament\Resources\SubscriptionResource\Pages;
use Lyre\Billing\Models\Subscription;
use UnitEnum;

class SubscriptionResource extends Resource
{
    protected static ?string $model = Subscription::class;

    protected static \BackedEnum|string|null $navigationIcon = 'gmdi-subscriptions';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return 'Subscriptions';
    }

    protected static ?int $navigationSort = 16;

    public static function form(Schema $form): Schema
    {
        return $form
            ->columns(2)
            ->schema([
                Forms\Components\DateTimePicker::make('start_date')
                    ->required(),
                // Forms\Components\DateTimePicker::make('end_date'),
                Forms\Components\Select::make('user_id')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Forms\Components\Select::make('subscription_plan_id')
                    ->relationship('subscriptionPlan', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                // Forms\Components\Placeholder::make('spacer')
                //     ->label('')
                //     ->content('')
                //     ->columnSpan(2),
                // Forms\Components\ToggleButtons::make('auto_renew')
                //     ->required()
                //     ->boolean()
                //     ->inline(),
                Forms\Components\ToggleButtons::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'active' => 'Active',
                        'paused' => 'Paused',
                        'canceled' => 'Canceled',
                        'expired' => 'Expired',
                    ])
                    ->inline()
                    ->required(),
                Forms\Components\TextInput::make('grace_days')
                    ->numeric()
                    ->nullable()
                    ->minValue(0)
                    ->maxValue(31)
                    ->placeholder('Inherit from plan / default')
                    ->helperText('Per-subscription grace override. Blank = plan or system default.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('access_summary')
                    ->label('Grants')
                    ->getStateUsing(fn (Subscription $record) => collect($record->accessComponents())
                        ->map(fn (array $component) => $component['kind'] === 'meter'
                            ? ($component['name'] ?? $component['meter']).': '.($component['usage_limit'] ?? '∞')
                            : ($component['name'] ?? ($component['type'] ?? 'item').($component['id'] ? ' #'.$component['id'] : ' (any)')))
                        ->implode(', '))
                    ->wrap()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('start_date')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('end_date')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'active' => 'success',
                        'paused' => 'warning',
                        'canceled' => 'danger',
                        'expired' => 'danger',
                    }),
                Tables\Columns\IconColumn::make('auto_renew')
                    ->boolean(),
                Tables\Columns\TextColumn::make('user.name')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('subscriptionPlan.name')
                    ->numeric()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('resyncAccess')
                    ->label('Re-sync access')
                    ->icon('gmdi-sync')
                    ->requiresConfirmation()
                    ->modalDescription('Replace what this subscription grants with the plan as it is configured now. Subscribers otherwise keep what they bought.')
                    ->action(fn (Subscription $record) => $record->captureAccessSnapshot(force: true))
                    ->successNotificationTitle('Access re-synced to the current plan'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->striped()
            ->deferLoading()
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscriptions::route('/'),
            'create' => Pages\CreateSubscription::route('/create'),
            'edit' => Pages\EditSubscription::route('/{record}/edit'),
        ];
    }
}
