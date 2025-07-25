<?php

namespace Lyre\Billing\Filament\Resources;

use Lyre\Billing\Filament\Resources\ProductResource\Pages;
use Lyre\Billing\Filament\Resources\ProductResource\RelationManagers;
use Lyre\Billing\Models\Product;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use FilamentTiptapEditor\TiptapEditor;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\HtmlString;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'gmdi-storefront';

    protected static ?string $navigationGroup = 'Payments';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('max_product_entities')
                    ->label('Maximum Products')
                    ->numeric()
                    ->required()
                    ->helperText(fn(): Htmlable => new HtmlString('<i><small>The maximum number of products that can be purchased for this product <br> <strong>Enter 0 for unlimited</strong></small></i>')),
                TiptapEditor::make('description')
                    ->columnSpanFull(),
                // Forms\Components\Select::make('pricing_model')
                //     ->options(collect(config('app.product_pricing_models'))
                //         ->mapWithKeys(fn($model) => [$model => ucwords(str_replace('_', ' ', $model))])
                //         ->toArray())
                //     ->default('fixed')
                //     ->hidden()
                //     ->required()
                //     ->dehydrated(true),
                Forms\Components\Hidden::make('pricing_model')
                    ->default('fixed')
                    ->required(),
                // Forms\Components\Select::make('user_id')
                //     ->relationship('user', 'name')
                //     ->preload()
                //     ->searchable()
                //     ->required(),
                Forms\Components\Hidden::make('user_id')
                    ->required()
                    ->default((User::where('email', admin_email())->first()?->id) ?? 1),
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

                Forms\Components\Placeholder::make('')
                    ->label('')
                    ->content(''),

                Forms\Components\Toggle::make('dynamic_entities')
                    ->label('Product entities dynamically selected at subscription')
                    ->required()
                    ->reactive()
                    ->default(false),

                Forms\Components\Placeholder::make('')
                    ->label('')
                    ->content(''),

                Forms\Components\Placeholder::make('')
                    ->label('')
                    ->content(''),

                Forms\Components\Section::make('Product Entities')
                    ->description('Add/Remove the entities that can be purchased for this product')
                    ->collapsible()
                    ->collapsed()
                    ->hidden(fn(callable $get) => $get('dynamic_entities') == true)
                    ->schema([
                        Forms\Components\Repeater::make('productEntities')
                            ->relationship()
                            ->columnSpanFull()
                            ->grid(2)
                            ->schema([
                                Forms\Components\Select::make('entity_type')
                                    ->options([
                                        // \App\Models\Exam::class => 'Exam',
                                        // \App\Models\Course::class => 'Course',
                                    ])
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
                            ])
                    ])
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
            ]);
    }

    public static function getRelations(): array
    {
        return [
            // RelationManagers\ProductEntitiesRelationManager::class
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
