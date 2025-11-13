<?php

namespace Lyre\Billing\Filament\Resources;

use Lyre\Billing\Filament\Resources\SubscriptionPlanBillableResource\Pages;
use Lyre\Billing\Models\SubscriptionPlanBillable;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class SubscriptionPlanBillableResource extends Resource
{
    protected static ?string $model = SubscriptionPlanBillable::class;

    protected static ?string $navigationIcon = 'gmdi-link';

    protected static ?string $navigationGroup = 'Payments';

    protected static ?int $navigationSort = 19;

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('subscription_plan_id')
                    ->label('Subscription Plan')
                    ->relationship('subscriptionPlan', 'name')
                    ->required()
                    ->searchable()
                    ->preload(),
                Forms\Components\Select::make('billable_id')
                    ->label('Billable')
                    ->relationship('billable', 'name')
                    ->required()
                    ->searchable()
                    ->preload(),
                Forms\Components\TextInput::make('usage_limit')
                    ->label('Usage Limit')
                    ->numeric()
                    ->nullable()
                    ->helperText('Optional: limit per billing period for this plan'),
                Forms\Components\TextInput::make('unit_price')
                    ->label('Unit Price')
                    ->numeric()
                    ->nullable()
                    ->helperText('Optional: override price for usage-based billing'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('subscriptionPlan.name')
                    ->label('Subscription Plan')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('billable.name')
                    ->label('Billable')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('usage_limit')
                    ->label('Usage Limit')
                    ->numeric()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('unit_price')
                    ->label('Unit Price')
                    ->money('USD')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->striped()
            ->deferLoading()
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscriptionPlanBillables::route('/'),
            'create' => Pages\CreateSubscriptionPlanBillable::route('/create'),
            'edit' => Pages\EditSubscriptionPlanBillable::route('/{record}/edit'),
        ];
    }
}

