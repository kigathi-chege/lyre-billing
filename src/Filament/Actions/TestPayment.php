<?php

namespace Lyre\Billing\Filament\Actions;

use Filament\Forms;
use Filament\Notifications\Notification;
use Lyre\Billing\Models\PaymentMethod;
use Lyre\Billing\Services\Mpesa\Client as MpesaClient;

class TestPayment
{
    /**
     * Create a table action for testing payments (default)
     * Use this in table actions
     */
    public static function make(?string $name = 'test_payment'): \Filament\Tables\Actions\Action
    {
        return \Filament\Tables\Actions\Action::make($name)
            ->label('Test Payment')
            ->icon('heroicon-o-credit-card')
            ->color('info')
            ->form(fn(PaymentMethod $record) => self::getFormFields($record))
            ->action(fn(PaymentMethod $record, array $data) => self::executePayment($record, $data))
            ->modalHeading(fn(PaymentMethod $record) => 'Test Payment - ' . $record->name)
            ->modalSubmitActionLabel('Send Payment')
            ->modalWidth('md');
    }

    /**
     * Create a page action for testing payments
     * Use this in page header actions
     */
    public static function makePageAction(?string $name = 'test_payment'): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make($name)
            ->label('Test Payment')
            ->icon('heroicon-o-credit-card')
            ->color('info')
            ->form(fn($livewire) => self::getFormFields($livewire->record))
            ->action(fn($livewire, array $data) => self::executePayment($livewire->record, $data))
            ->modalHeading(fn($livewire) => 'Test Payment - ' . $livewire->record->name)
            ->modalSubmitActionLabel('Send Payment')
            ->modalWidth('md');
    }

    protected static function getFormFields(PaymentMethod $record): array
    {
        $normalizedName = strtolower(preg_replace('/[^a-z0-9]+/i', '', $record->name));

        // Base fields for all payment methods
        $baseFields = [
            Forms\Components\TextInput::make('amount')
                ->label('Amount')
                ->required()
                ->numeric()
                ->minValue(1)
                // ->prefix('$')
                ->default(1.00)
                ->helperText('Enter the test payment amount'),
        ];

        // M-Pesa specific fields
        if ($normalizedName === 'mpesa') {
            return array_merge($baseFields, [
                Forms\Components\TextInput::make('phone_number')
                    ->label('Phone Number')
                    ->required()
                    ->tel()
                    ->placeholder('254712345678')
                    ->helperText('Enter phone number in format: 254712345678'),
                Forms\Components\TextInput::make('party_a')
                    ->label('Party A (Optional)')
                    ->tel()
                    ->placeholder('254712345678')
                    ->helperText('Leave blank to use phone number'),
            ]);
        }

        // PayPal specific fields
        if ($normalizedName === 'paypal') {
            return array_merge($baseFields, [
                Forms\Components\TextInput::make('email')
                    ->label('PayPal Email')
                    ->required()
                    ->email()
                    ->placeholder('customer@example.com')
                    ->helperText('Customer PayPal email address'),
                Forms\Components\Textarea::make('description')
                    ->label('Payment Description')
                    ->rows(3)
                    ->placeholder('Test payment for...')
                    ->default('Test payment'),
            ]);
        }

        // Default fields for other payment methods
        return array_merge($baseFields, [
            Forms\Components\Textarea::make('payment_details')
                ->label('Payment Details (JSON)')
                ->rows(5)
                ->placeholder('{"key": "value"}')
                ->helperText('Enter payment-specific details in JSON format'),
        ]);
    }

    protected static function executePayment(PaymentMethod $record, array $data): void
    {
        $normalizedName = strtolower(preg_replace('/[^a-z0-9]+/i', '', $record->name));

        try {
            if ($normalizedName === 'mpesa') {
                $mpesaClient = new MpesaClient();
                $response = $mpesaClient->express(
                    partyA: $data['party_a'] ?? null,
                    phoneNumber: $data['phone_number'],
                    amount: $data['amount'],
                    paymentMethod: $record
                );

                Notification::make()
                    ->title('M-Pesa Payment Initiated')
                    ->success()
                    ->body('STK Push sent successfully. Check your phone.')
                    ->send();

                return;
            }

            if ($normalizedName === 'paypal') {
                // PayPal test payment logic would go here
                Notification::make()
                    ->title('PayPal Payment Test')
                    ->warning()
                    ->body('PayPal test payment is not yet implemented.')
                    ->send();

                return;
            }

            // Generic payment method
            Notification::make()
                ->title('Payment Test')
                ->info()
                ->body('Test payment feature not implemented for this payment method.')
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Payment Failed')
                ->danger()
                ->body($e->getMessage())
                ->send();
        }
    }
}
