<?php

namespace Lyre\Billing\Services\Stripe;

final class ProviderData
{
    public static function id(mixed $value): string
    {
        return is_string($value) ? $value : (string) data_get($value, 'id', '');
    }

    public static function subscriptionId(mixed $invoice): string
    {
        return self::id(data_get($invoice, 'subscription') ?: data_get($invoice, 'parent.subscription_details.subscription'));
    }

    public static function periodEnd(mixed $subscription): ?int
    {
        $ends = collect(data_get($subscription, 'items.data', []))->pluck('current_period_end')->filter();

        return data_get($subscription, 'current_period_end') ?: ($ends->count() ? (int) $ends->min() : null);
    }

    public static function servicePeriod(mixed $invoice): array
    {
        $lines = collect(data_get($invoice, 'lines.data', []))->filter(fn ($l) => data_get($l, 'type') === 'subscription' || data_get($l, 'parent.type') === 'subscription_item_details');
        if ($lines->isEmpty()) {
            return [null, null];
        }

        return [$lines->pluck('period.start')->filter()->min(), $lines->pluck('period.end')->filter()->min()];
    }

    public static function canonicalMoney(string $value): string
    {
        $parts = explode('.', $value, 2);
        $whole = ltrim($parts[0], '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = rtrim($parts[1] ?? '', '0');

        return $whole.($fraction !== '' ? '.'.$fraction : '');
    }

    public static function decimal(int $minor, string $currency): string
    {
        $zero = ['bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf'];
        $three = ['bhd', 'jod', 'kwd', 'omr', 'tnd'];
        $digits = in_array(strtolower($currency), $zero, true) ? 0 : (in_array(strtolower($currency), $three, true) ? 3 : 2);
        if (! $digits) {
            return (string) $minor;
        }
        $s = str_pad((string) abs($minor), $digits + 1, '0', STR_PAD_LEFT);

        return ($minor < 0 ? '-' : '').substr($s, 0, -$digits).'.'.substr($s,-$digits);
    }
}
