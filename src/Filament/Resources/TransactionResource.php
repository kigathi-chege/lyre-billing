<?php

namespace Lyre\Billing\Filament\Resources;

use Lyre\Billing\Filament\Resources\TransactionResource\Pages;
use Lyre\Billing\Filament\Resources\TransactionResource\RelationManagers;
use Lyre\Billing\Models\Transaction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use ValentinMorice\FilamentJsonColumn\JsonColumn;

class TransactionResource extends Resource
{
    protected static ?string $model = Transaction::class;

    protected static ?string $navigationIcon = 'gmdi-currency-exchange';

    protected static ?string $navigationGroup = 'Payments';

    protected static ?int $navigationSort = 21;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('uuid')
                    ->disabled()
                    ->dehydrated(false)
                    ->visible(fn($record) => $record !== null),
                Forms\Components\Select::make('status')
                    ->required()
                    ->options([
                        'pending' => 'Pending',
                        'completed' => 'Completed',
                        'failed' => 'Failed',
                        'cancelled' => 'Cancelled',
                        'refunded' => 'Refunded',
                    ])
                    ->default('pending'),
                Forms\Components\TextInput::make('amount')
                    ->required()
                    ->numeric()
                    ->default(0.00)
                    ->minValue(0),
                Forms\Components\TextInput::make('provider_reference')
                    ->maxLength(255)
                    ->nullable()
                    ->helperText('External payment provider reference number'),
                Forms\Components\TextInput::make('currency')
                    ->required()
                    ->default('KES')
                    ->maxLength(3)
                    ->placeholder('e.g., USD, KES, EUR'),
                Forms\Components\Select::make('invoice_id')
                    ->relationship('invoice', 'invoice_number')
                    ->searchable()
                    ->preload()
                    ->nullable(),
                Forms\Components\Select::make('user_id')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload()
                    ->nullable(),
                Forms\Components\Select::make('payment_method_id')
                    ->relationship('paymentMethod', 'name')
                    ->required()
                    ->searchable()
                    ->preload(),

                JsonColumn::make('raw_request')
                    ->label('Raw Provider Request')
                    ->columnSpanFull()
                    ->helperText('Raw request data send to payment provider'),

                Forms\Components\MarkdownEditor::make('raw_response')
                    ->columnSpanFull()
                    ->maxHeight('50vh')
                    ->visible(fn($get) => ! is_json($get('raw_response'))),

                JsonColumn::make('raw_response')
                    ->visible(fn($get) => is_json($get('raw_response')))
                    ->columnSpanFull(),

                JsonColumn::make('raw_callback')
                    ->label('Raw Provider Callback')
                    ->columnSpanFull()
                    ->helperText('Raw callback data from payment provider'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'completed',
                        'danger' => 'failed',
                        'gray' => 'cancelled',
                        'info' => 'refunded',
                    ])
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount')
                    ->money(fn($record) => $record->currency ?? 'USD')
                    ->sortable(),
                Tables\Columns\TextColumn::make('currency')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('provider_reference')
                    ->searchable()
                    ->limit(30)
                    ->copyable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('invoice.invoice_number')
                    ->label('Invoice')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('paymentMethod.name')
                    ->label('Payment Method')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'completed' => 'Completed',
                        'failed' => 'Failed',
                        'cancelled' => 'Cancelled',
                        'refunded' => 'Refunded',
                    ]),
                Tables\Filters\SelectFilter::make('currency')
                    ->options([
                        'USD' => 'USD',
                        'KES' => 'KES',
                        'EUR' => 'EUR',
                        'GBP' => 'GBP',
                    ]),
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
            'index' => Pages\ListTransactions::route('/'),
            'create' => Pages\CreateTransaction::route('/create'),
            'edit' => Pages\EditTransaction::route('/{record}/edit'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::user()->can('update', Transaction::class);
    }
}
