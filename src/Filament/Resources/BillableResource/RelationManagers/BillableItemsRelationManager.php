<?php

namespace Lyre\Billing\Filament\Resources\BillableResource\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema as DatabaseSchema;
use Illuminate\Support\Str;
use Lyre\Billing\Models\BillableItem;
use Lyre\Billing\Support\BillingRules;

class BillableItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'billableItems';

    /**
     * Item types an admin can sell: content types from billing.entitlements.type_map, allowance
     * meters, and legacy function items.
     *
     * @return array<string, string>
     */
    protected static function itemTypeOptions(): array
    {
        $content = collect((array) config('billing.entitlements.type_map', []))
            ->filter(fn ($class) => $class && class_exists($class))
            ->mapWithKeys(fn ($class, $token) => [$token => Str::headline($token)])
            ->all();

        return $content + [
            BillableItem::TYPE_METER => 'Allowance meter',
            BillableItem::TYPE_FUNCTION => 'Function (legacy)',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected static function meterOptions(): array
    {
        $configured = (array) config('billing.allowances.meters', []);
        $existing = BillableItem::query()
            ->where('item_type', BillableItem::TYPE_METER)
            ->distinct()
            ->pluck('item_id')
            ->mapWithKeys(fn ($key) => [$key => Str::headline((string) $key)])
            ->all();

        return $configured + $existing;
    }

    protected static function recordLabel(?Model $record): ?string
    {
        if (! $record) {
            return null;
        }

        return (string) ($record->getAttribute('name') ?? $record->getAttribute('title') ?? $record->getAttribute('slug') ?? $record->getKey());
    }

    protected static function isContentType(?string $type): bool
    {
        return $type !== null && BillableItem::classForToken($type) !== null;
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Forms\Components\Select::make('item_type')
                    ->label('Sells')
                    ->options(fn () => static::itemTypeOptions())
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (callable $set) {
                        $set('item_id', null);
                        $set('metadata', []);
                    }),
                Forms\Components\Select::make('item_id')
                    ->label(fn (callable $get) => $get('item_type') === BillableItem::TYPE_METER ? 'Meter' : 'Item')
                    ->placeholder(fn (callable $get) => static::isContentType($get('item_type')) ? 'Any (customer picks, or all)' : null)
                    ->helperText(fn (callable $get) => static::isContentType($get('item_type'))
                        ? 'Leave empty to sell "any" of this type: the plan\'s usage limit is the number of picks (empty = all, including future content).'
                        : null)
                    ->searchable()
                    ->options(function (callable $get) {
                        $type = $get('item_type');

                        if ($type === BillableItem::TYPE_METER) {
                            return static::meterOptions();
                        }

                        if ($type === BillableItem::TYPE_FUNCTION) {
                            return collect(get_billable_methods())
                                ->mapWithKeys(fn ($value) => [$value['class'] => $value['args'][0]])
                                ->all();
                        }

                        return [];
                    })
                    ->getSearchResultsUsing(function (string $search, callable $get) {
                        $class = BillableItem::classForToken($get('item_type'));

                        if (! $class) {
                            return [];
                        }

                        $model = new $class;
                        $columns = collect(['name', 'title', 'slug'])
                            ->filter(fn ($column) => DatabaseSchema::hasColumn($model->getTable(), $column));

                        return $class::query()
                            ->where(function ($query) use ($columns, $search) {
                                foreach ($columns as $column) {
                                    $query->orWhere($column, 'ilike', "%{$search}%");
                                }
                            })
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn ($record) => [(string) $record->getKey() => static::recordLabel($record)])
                            ->all();
                    })
                    ->getOptionLabelUsing(function ($value, callable $get) {
                        $type = $get('item_type');
                        $class = BillableItem::classForToken($type);

                        if ($class) {
                            return static::recordLabel($class::query()->find((int) $value));
                        }

                        return static::meterOptions()[$value] ?? $value;
                    })
                    ->required(fn (callable $get) => in_array($get('item_type'), [BillableItem::TYPE_METER, BillableItem::TYPE_FUNCTION], true))
                    ->live(),
                Forms\Components\TextInput::make('name')
                    ->maxLength(255)
                    ->helperText('Shown in plan benefits; defaults to the bundle name.'),
                Forms\Components\CheckboxList::make('metadata.capabilities')
                    ->label('Grants')
                    ->options(fn () => (array) config('billing.entitlements.capabilities', []))
                    ->helperText(fn (callable $get) => 'Leave empty for the defaults: '.implode(', ', BillableItem::defaultCapabilities($get('item_type'))))
                    ->visible(fn (callable $get) => static::isContentType($get('item_type'))),
                Forms\Components\KeyValue::make('metadata.scope')
                    ->label('Scope')
                    ->helperText('Narrow shared content, e.g. course_id for a subject or topic used in several courses.')
                    ->visible(fn (callable $get) => in_array($get('item_type'), ['subject', 'topic'], true)),
                Forms\Components\Select::make('pricing_model')
                    ->options([
                        'free' => 'Free',
                        'fixed' => 'Fixed',
                        'quota_based' => 'Quota based',
                        'usage_based' => 'Usage based',
                    ])
                    ->default(fn (callable $get) => $get('item_type') === BillableItem::TYPE_METER ? 'quota_based' : 'fixed')
                    ->required(),
                Forms\Components\TextInput::make('usage_limit')
                    ->label(fn (callable $get) => $get('item_type') === BillableItem::TYPE_METER ? 'Included per period' : 'Picks')
                    ->helperText('Default for plans that include this bundle; each plan can override it. Empty = unlimited.')
                    ->numeric()
                    ->minValue(0)
                    ->visible(fn (callable $get) => $get('item_type') === BillableItem::TYPE_METER || (static::isContentType($get('item_type')) && blank($get('item_id')))),
                Forms\Components\Select::make('reset_period')
                    ->options(array_combine(BillingRules::RESET_PERIODS, array_map(fn ($period) => Str::headline($period), BillingRules::RESET_PERIODS)))
                    ->default('billing_cycle')
                    ->visible(fn (callable $get) => $get('item_type') === BillableItem::TYPE_METER),
                Forms\Components\TextInput::make('metadata.per_resource_limit')
                    ->label('Limit per item (e.g. attempts per assessment)')
                    ->numeric()
                    ->minValue(0)
                    ->visible(fn (callable $get) => $get('item_type') === BillableItem::TYPE_METER),
                Forms\Components\Select::make('metadata.on_exhaustion')
                    ->label('When used up')
                    ->options(['upsell' => 'Block and offer an upgrade', 'overage' => 'Charge overage'])
                    ->placeholder('Default ('.config('billing.allowances.on_exhaustion', 'upsell').')')
                    ->visible(fn (callable $get) => $get('item_type') === BillableItem::TYPE_METER),
                Forms\Components\TextInput::make('unit_price')
                    ->label('Overage price per unit')
                    ->numeric()
                    ->minValue(0)
                    ->visible(fn (callable $get) => $get('item_type') === BillableItem::TYPE_METER),
                Forms\Components\TextInput::make('currency')
                    ->maxLength(3)
                    ->visible(fn (callable $get) => $get('item_type') === BillableItem::TYPE_METER),
                Forms\Components\TextInput::make('metadata.unit')
                    ->label('Unit')
                    ->placeholder('attempt')
                    ->visible(fn (callable $get) => $get('item_type') === BillableItem::TYPE_METER),
                Forms\Components\Select::make('status')
                    ->options(['active' => 'Active', 'inactive' => 'Inactive'])
                    ->default('active')
                    ->required(),
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
                    ->label('Sells')
                    ->badge()
                    ->formatStateUsing(fn ($state) => static::itemTypeOptions()[$state] ?? $state),
                Tables\Columns\TextColumn::make('item_id')
                    ->label('Item')
                    ->placeholder('Any')
                    ->formatStateUsing(function ($state, BillableItem $record) {
                        $class = $record->contentClass();

                        return $class && $record->contentId() ? static::recordLabel($class::query()->find($record->contentId())) : $state;
                    }),
                Tables\Columns\TextColumn::make('usage_limit')
                    ->label('Usage Limit')
                    ->numeric()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('unit_price')
                    ->label('Unit Price')
                    ->numeric()
                    ->money(fn ($record) => $record->currency ?? 'USD')
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
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
