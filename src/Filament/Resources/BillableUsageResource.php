<?php

namespace Lyre\Billing\Filament\Resources;

use Lyre\Billing\Filament\Resources\BillableUsageResource\Pages;
use Lyre\Billing\Models\BillableUsage;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class BillableUsageResource extends Resource
{
    protected static ?string $model = BillableUsage::class;

    protected static ?string $navigationIcon = 'gmdi-data-usage';

    protected static ?string $navigationGroup = 'Payments';

    protected static ?int $navigationSort = 20;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('billable_item_id')
                    ->label('Billable Item')
                    ->relationship('billableItem', 'name')
                    ->getOptionLabelFromRecordUsing(fn($record) => $record->name ?? "Billable Item #{$record->id}")
                    ->required()
                    ->searchable()
                    ->preload(),
                Forms\Components\Select::make('user_id')
                    ->label('User')
                    ->relationship('user', 'name')
                    ->required()
                    ->searchable()
                    ->preload(),
                Forms\Components\TextInput::make('amount')
                    ->required()
                    ->numeric()
                    ->default(0.00)
                    ->minValue(0)
                    ->helperText('Total amount charged for this usage'),
                Forms\Components\DateTimePicker::make('recorded_at')
                    ->required()
                    ->default(now())
                    ->helperText('When this usage was recorded'),
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
                Tables\Columns\TextColumn::make('billableItem.name')
                    ->label('Billable Item')
                    ->formatStateUsing(fn($state, $record) => $state ?? "Billable Item #{$record->billableItem->id}")
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('User')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount')
                    ->money(fn($record) => $record->currency ?? 'USD')
                    ->sortable(),
                Tables\Columns\TextColumn::make('recorded_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('billable_item_id')
                    ->label('Billable Item')
                    ->relationship('billableItem', 'name', modifyQueryUsing: fn($query) => $query->whereNotNull('name'))
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('user_id')
                    ->label('User')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                // Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->striped()
            ->deferLoading()
            ->defaultSort('recorded_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBillableUsages::route('/'),
            // 'create' => Pages\CreateBillableUsage::route('/create'),
            // 'edit' => Pages\EditBillableUsage::route('/{record}/edit'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::user()->can('update', BillableUsage::class);
    }
}
