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
                    ->default(0.00)
                    ->rule('gt:0')
                    ->prefix('$')
                    ->helperText('Price must be greater than $0.00'),
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
                Forms\Components\Select::make('product_type')
                    ->options([
                        // \App\Models\Exam::class => 'Exam',
                        // \App\Models\Course::class => 'Course',
                        // \App\Models\Product::class => 'Custom Product',
                    ])
                    // ->default(\App\Models\Exam::class)
                    ->searchable()
                    ->reactive()
                    ->afterStateUpdated(fn(callable $set) => $set('product_id', null)),
                Forms\Components\Select::make('product_id')
                    ->label('Product')
                    ->helperText('Please choose a type first')
                    ->required()
                    ->options(function (callable $get) {
                        $productType = $get('product_type');

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
                TiptapEditor::make('description')
                    ->columnSpanFull()
                    ->required(),
                Forms\Components\Select::make('categories')
                    ->label('Categories')
                    ->relationship(
                        name: 'facetValues',
                        titleAttribute: 'name',
                        // modifyQueryUsing: fn(\Illuminate\Database\Eloquent\Builder $query) => $query->withTrashed(),
                        // TODO: Kigathi - May 18 2025 - This works because Articles is the only entity we are currently using FacetValues on
                        modifyQueryUsing: fn() => \Lyre\Facet\Models\FacetValue::query(),
                    )
                    ->multiple()
                    ->preload()
                    ->searchable()
                    ->getOptionLabelFromRecordUsing(fn($record) => "{$record->name} ({$record->facet_name})")
                    ->saveRelationshipsUsing(static function ($component, $record, $state) {
                        if (!empty($state)) {
                            $record->attachFacetValues($state);
                        }
                    }),
                Forms\Components\ToggleButtons::make('status')
                    ->options([
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                    ])
                    ->inline(),
                JsonColumn::make('features'),

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
                Tables\Columns\TextColumn::make('product.name')
                    ->numeric()
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
            //
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
        return Auth::user()->can('update', Auth::user(), SubscriptionPlan::class);
    }
}
