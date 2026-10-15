<?php

namespace Lyre\Billing\Services\Stripe;

use Carbon\Carbon;
use Lyre\Billing\Models\BillingProviderInvoice;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;

class ProviderReader
{
    public array $forceInvoiceIds = [];

    public function __construct()
    {
        $http = new CurlClient;
        $http->setTimeout(15);
        $http->setConnectTimeout(5);
        ApiRequestor::setHttpClient($http);
    }

    protected function options(string $account): array
    {
        return $account === 'platform' ? [] : ['stripe_account' => $account];
    }

    public function relatedObject(string $url, string $account): array
    {
        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        if (! str_starts_with($path, '/v1/') || str_contains($path, '..')) {
            throw new \RuntimeException('Unsupported Stripe related object path.');
        }

        return Client::make()->request('get', $path, [], $this->options($account))->toArray();
    }

    public function subscription(string $id, string $account = 'platform'): array
    {
        return Client::make()->subscriptions->retrieve($id, [], $this->options($account))->toArray();
    }

    public function invoices(string $subscription, string $account = 'platform'): array
    {
        $result = [];
        $started = microtime(true);
        $verified = 0;
        foreach (Client::make()->invoices->all(['subscription' => $subscription, 'limit' => 100], $this->options($account))->autoPagingIterator() as $invoice) {
            if (microtime(true) - $started > 60) {
                throw new \RuntimeException('Provider history exceeded the bounded request window; retry a smaller scope.');
            }
            $row = $invoice->toArray();
            if (data_get($row, 'lines.has_more')) {
                $row['lines']['data'] = [];
                foreach (Client::make()->invoices->allLines($row['id'], ['limit' => 100], $this->options($account))->autoPagingIterator() as $line) {
                    $row['lines']['data'][] = $line->toArray();
                }
            }
            $known = BillingProviderInvoice::where(['provider' => 'stripe', 'account' => $account, 'livemode' => (bool) ($row['livemode'] ?? false), 'provider_invoice_id' => $row['id']])->first();
            $snapshot = $known?->snapshot ?? [];
            $last = $snapshot['_payments_verified_at'] ?? null;
            $fresh = $last && Carbon::parse($last)->greaterThan(now()->subSeconds($row['status'] === 'paid' ? 86400 : 60));
            $same = $known && $known->status === $row['status'] && (int) $known->amount_paid_minor === (int) $row['amount_paid'];
            if ($same && $fresh && ! in_array($row['id'], $this->forceInvoiceIds, true)) {
                $row['_payments'] = $snapshot['_payments'] ?? [];
                $row['_payments_verified_at'] = $last;
            } elseif ($verified < 20 && microtime(true) - $started < 55) {
                $row['_payments'] = $this->payments($row, $account);
                $row['_payments_verified_at'] = now()->toIso8601String();
                $verified++;
            } else {
                $row['_payments'] = $snapshot['_payments'] ?? [];
                $row['_payments_verified_at'] = $last;
                $row['_payments_deferred'] = true;
            }
            $result[] = $row;
        }

        return $result;
    }

    public function payments(array $invoice, string $account): array
    {
        if ($intent = ProviderData::id($invoice['payment_intent'] ?? null)) {
            $pi = Client::make()->paymentIntents->retrieve($intent, [], $this->options($account))->toArray();

            return [$this->enrichPayment($pi, $account)];
        }
        // Modern invoices expose InvoicePayment records rather than a top-level intent.
        if (array_key_exists('payments', $invoice)) {
            $rows = [];
            $params = ['invoice' => $invoice['id'], 'limit' => 100];
            do {
                $page = Client::make()->request('get', '/v1/invoice_payments', $params, $this->options($account))->toArray();
                foreach ($page['data'] ?? [] as $payment) {
                    $intent = ProviderData::id(data_get($payment, 'payment.payment_intent'));
                    $rows[] = $intent ? $this->enrichPayment(Client::make()->paymentIntents->retrieve($intent, [], $this->options($account))->toArray(), $account) : ['id' => $payment['id'], 'status' => $payment['status'] === 'paid' ? 'succeeded' : $payment['status'], 'amount_received' => $payment['amount_paid'] ?? 0, 'currency' => $payment['currency'] ?? $invoice['currency']];
                }
                if (! empty($page['has_more']) && empty($page['data'])) {
                    throw new \RuntimeException('Stripe payment pagination did not advance.');
                }
                $params['starting_after'] = data_get($page, 'data.'.(count($page['data'] ?? []) - 1).'.id');
            } while (! empty($page['has_more']));

            return $rows;
        }

        return []; // Do not invent a cash payment from invoice settlement/credit alone.
    }

    protected function enrichPayment(array $payment, string $account): array
    {
        $chargeId = ProviderData::id($payment['latest_charge'] ?? null) ?: ProviderData::id(data_get($payment, 'last_payment_error.charge'));
        if (! $chargeId) {
            return $payment;
        }
        $charge = Client::make()->charges->retrieve($chargeId, [], $this->options($account))->toArray();
        $payment['latest_charge'] = $charge;
        $payment['_refunded_minor'] = (int) ($charge['amount_refunded'] ?? 0);
        $payment['_disputed'] = (bool) ($charge['disputed'] ?? false);
        if ($payment['_refunded_minor']) {
            $payment['_refunds'] = [];
            foreach (Client::make()->refunds->all(['charge' => $chargeId, 'limit' => 100], $this->options($account))->autoPagingIterator() as $refund) {
                $payment['_refunds'][] = $refund->toArray();
            }
            $payment['status'] = $payment['_refunded_minor'] >= (int) ($charge['amount'] ?? PHP_INT_MAX) ? 'refunded' : 'partially_refunded';
        }
        if ($payment['_disputed']) {
            $payment['status'] = 'disputed';
        }

        return $payment;
    }
}
