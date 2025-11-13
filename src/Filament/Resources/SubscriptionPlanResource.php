<?php

namespace Lyre\Billing\Filament\Resources;

use Lyre\Billing\Filament\Clusters\Subscriptions;
use Lyre\Billing\Filament\Resources\SubscriptionPlanResource\Pages;
use Lyre\Billing\Filament\Resources\SubscriptionPlanResource\RelationManagers;
use Lyre\Billing\Models\SubscriptionPlan;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use FilamentTiptapEditor\TiptapEditor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use ValentinMorice\FilamentJsonColumn\JsonColumn;

class SubscriptionPlanResource extends Resource
{
    protected static ?string $model = SubscriptionPlan::class;

    protected static ?string $navigationIcon = 'gmdi-workspace-premium';

    protected static ?string $navigationGroup = 'Payments';

    protected static ?int $navigationSort = 17;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('price')
                    ->required()
                    ->numeric()
                    ->default(0.00),
                Forms\Components\TextInput::make('currency')
                    ->required()
                    ->maxLength(3)
                    ->default('KES'),
                Forms\Components\Select::make('billing_cycle')
                    ->required()
                    ->options([
                        // NOTE: Kigathi - May 27 2025 - Comment out cycles not supported by PayPal
                        // 'per_minute' => 'Per Minute',
                        // 'per_hour' => 'Per Hour',
                        'per_day' => 'Per Day',
                        'per_week' => 'Per Week',
                        'monthly' => 'Monthly',
                        // 'quarterly' => 'Quarterly',
                        // 'semi_annually' => 'Semi Annually',
                        'annually' => 'Annually',
                    ])
                    ->default('monthly'),
                Forms\Components\TextInput::make('trial_days')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\Select::make('status')
                    ->options([
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                    ]),
                TiptapEditor::make('description')
                    ->columnSpanFull(),

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
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('price')
                    ->money()
                    ->sortable(),
                Tables\Columns\TextColumn::make('billing_cycle')
                    ->badge()
                    ->colors([
                        'per_minute' => 'danger',
                        'per_hour' => 'danger',
                        'per_day' => 'gray',
                        'per_week' => 'gray',
                        'monthly' => 'success',
                        'quarterly' => 'success',
                        'semi_annually' => 'warning',
                        'annually' => 'warning',
                    ]),
                Tables\Columns\TextColumn::make('trial_days')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'active' => 'success',
                        'inactive' => 'danger',
                    ])
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
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

    public static function getRelations(): array
    {
        return [
            RelationManagers\SubscriptionPlanBillablesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscriptionPlans::route('/'),
            'create' => Pages\CreateSubscriptionPlan::route('/create'),
            'edit' => Pages\EditSubscriptionPlan::route('/{record}/edit'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        // $permissions = config('filament-shield.permission_prefixes.resource');
        // TODO: Kigathi - May 4 2025 - Users should only view this navigation if they have at least one more permission than view and viewAny
        return Auth::user()->can('update', SubscriptionPlan::class);
    }
}
