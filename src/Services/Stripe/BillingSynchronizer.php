<?php

namespace Lyre\Billing\Services\Stripe;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lyre\Billing\Models\BillingPayment;
use Lyre\Billing\Models\BillingProviderInvoice;
use Lyre\Billing\Models\BillingStateChange;
use Lyre\Billing\Models\Invoice;
use Lyre\Billing\Models\PaymentMethod;
use Lyre\Billing\Models\Transaction;

class BillingSynchronizer
{
    public function __construct(protected ProviderReader $reader) {}

    public function synchronize(Model $subscription, bool $dryRun = true, string $source = 'reconciliation', string $correlation = '', string $account = 'platform', ?int $actor = null, ?string $expectedHash = null, ?bool $expectedMode = null, array $forceInvoiceIds = [], ?string $eventType = null): array
    {
        $correlation = $correlation !== '' ? $correlation : (string) Str::uuid();
        $id = StripeModelBridge::getSubscriptionId($subscription);
        if (! $id) {
            throw new \RuntimeException('Subscription has no Stripe reference.');
        }

        return Cache::lock('billing-sync:'.$account.':'.$id, 120)->block(5, function () use ($subscription, $id, $dryRun, $source, $correlation, $account, $actor, $expectedHash, $expectedMode, $forceInvoiceIds, $eventType) {
            $subscription->refresh();
            $live = $this->reader->subscription($id, $account);
            $this->reader->forceInvoiceIds = $source === 'webhook' ? array_filter(array_merge($forceInvoiceIds, [ProviderData::id($live['latest_invoice'] ?? null)])) : $forceInvoiceIds;
            $invoices = $this->reader->invoices($id, $account);
            $customer = ProviderData::id($live['customer'] ?? null);
            $expected = (string) data_get($subscription->metadata, 'providers.stripe.customer_id', '');
            if ($expected && $customer !== $expected) {
                throw new \RuntimeException('Stripe customer does not match the local subscription.');
            }
            $mode = (bool) ($live['livemode'] ?? false);
            if ($expectedMode !== null && $mode !== $expectedMode) {
                throw new \RuntimeException('Webhook and provider account modes differ.');
            }
            $manualReview = false;
            $paidThrough = null;
            foreach ($invoices as $invoice) {
                if (ProviderData::subscriptionId($invoice) !== $id || ProviderData::id($invoice['customer'] ?? null) !== $customer || (bool) ($invoice['livemode'] ?? false) !== $mode) {
                    throw new \RuntimeException('Invoice identity does not match subscription/account/mode.');
                }
                [, $end] = ProviderData::servicePeriod($invoice);
                $refunded = collect($invoice['_payments'] ?? [])->sum('_refunded_minor');
                $manualReview = $manualReview || collect($invoice['_payments'] ?? [])->contains(fn ($p) => in_array($p['status'] ?? '', ['partially_refunded', 'disputed'], true));
                if ($refunded > 0 && $refunded >= (int) ($invoice['amount_paid'] ?? 0)) {
                    continue;
                }
                if (($invoice['status'] ?? '') === 'paid') {
                    if (! $end) {
                        throw new \RuntimeException('Paid invoice has no identifiable subscription service period.');
                    }
                    $paidThrough = max($paidThrough ?? 0, (int) $end);
                }
            }
            $status = (string) ($live['status'] ?? '');
            if (! in_array($status, ['active', 'trialing', 'past_due', 'canceled', 'incomplete', 'incomplete_expired', 'unpaid', 'paused'], true)) {
                throw new \RuntimeException('Unrecognized provider subscription state; review required.');
            }
            if ($status === 'trialing') {
                $paidThrough = max($paidThrough ?? 0, (int) ($live['trial_end'] ?? 0));
            }
            $grace = (int) ($subscription->grace_days ?? $subscription->subscriptionPlan?->grace_days ?? config('billing.grace_days', 2));
            $providerPaidThrough = $paidThrough;
            $testExpiry = TestExpiry::at($subscription, $mode);
            if ($testExpiry) {
                $paidThrough = $paidThrough ? min($paidThrough, $testExpiry->timestamp) : null;
                $grace = 0;
            }
            $covered = $paidThrough && $paidThrough > now()->timestamp;
            $inGrace = $paidThrough && $paidThrough + $grace * 86400 > now()->timestamp;
            $localStatus = ($covered || $inGrace) ? 'active' : match ($status) {
                'canceled' => 'canceled','incomplete' => 'pending',default => 'paused'
            };
            $reason = $covered ? ($status === 'trialing' ? 'trial' : 'settled_invoice') : ($inGrace ? 'configured_grace' : 'no_current_settled_coverage');
            if ($testExpiry) {
                $reason = 'uat_test_expiry';
            }
            $prices = collect(data_get($live, 'items.data', []))->map(fn ($i) => ['id' => data_get($i, 'price.id'), 'currency' => data_get($i, 'price.currency'), 'unit_amount_minor' => data_get($i, 'price.unit_amount'), 'interval' => data_get($i, 'price.recurring.interval'), 'interval_count' => data_get($i, 'price.recurring.interval_count')])->all();
            $catalogPrice = (string) ($subscription->subscriptionPlan?->price ?? '');
            $before = $this->state($subscription->fresh());
            $after = ['status' => $localStatus, 'auto_renew' => ! in_array($status, ['canceled', 'incomplete_expired'], true) && ! ($live['cancel_at_period_end'] ?? false), 'end_date' => $paidThrough ? gmdate('c', $paidThrough) : null, 'provider_status' => $status, 'current_period_end' => ProviderData::periodEnd($live), 'access_reason' => $reason, 'contract_prices' => $prices, 'catalog_price' => $catalogPrice, 'provider_paid_through' => $providerPaidThrough, 'test_expiry_at' => $testExpiry?->toIso8601String()];
            if ($manualReview) {
                $after['status'] = $before['status'];
                $after['end_date'] = $before['end_date'];
                $after['access_reason'] = 'manual_review_refund_or_dispute';
            }
            $differences = [];
            if ($before != $after) {
                $differences[] = ['kind' => 'subscription', 'before' => $before, 'after' => $after];
            }
            foreach ($invoices as $invoice) {
                $known = BillingProviderInvoice::where(['provider' => 'stripe', 'account' => $account, 'livemode' => $mode, 'provider_invoice_id' => $invoice['id']])->first();
                $amount = (int) ($invoice['amount_due'] ?? 0);
                $paid = (int) ($invoice['amount_paid'] ?? 0);
                $local = $this->localInvoice($subscription, $invoice, $known);
                if (! empty($invoice['_payments_verified_at']) && data_get($known?->snapshot, '_payments_verified_at') !== $invoice['_payments_verified_at']) {
                    $differences[] = ['kind' => 'verification', 'provider_id' => $invoice['id']];
                }
                $invoiceStatus = match ($invoice['status']) {
                    'paid' => 'paid','void','uncollectible' => 'failed',default => 'pending'
                };
                $projectionMismatch = ! $local || $local->status !== $invoiceStatus || ProviderData::canonicalMoney((string) $local->amount) !== ProviderData::canonicalMoney(ProviderData::decimal($amount, $invoice['currency'])) || ProviderData::canonicalMoney((string) $local->amount_paid) !== ProviderData::canonicalMoney(ProviderData::decimal($paid, $invoice['currency']));
                if (! $known || $known->status !== $invoice['status'] || (int) $known->amount_due_minor !== $amount || (int) $known->amount_paid_minor !== $paid || $projectionMismatch) {
                    $differences[] = ['kind' => 'invoice', 'provider_id' => $invoice['id'], 'status' => $invoice['status'], 'amount_due_minor' => $amount, 'amount_paid_minor' => $paid];
                }
                if ($local && ! empty($invoice['_payments'])) {
                    foreach ($this->legacyTransactions($local->id) as $legacy) {
                        $differences[] = ['kind' => 'legacy_telemetry', 'transaction_id' => $legacy->id, 'before' => $legacy->status, 'after' => 'superseded', 'reason' => 'Authoritative provider payment is recorded separately.'];
                    }
                }
                foreach ($invoice['_payments'] ?? [] as $payment) {
                    $pid = $this->paymentId($payment);
                    $knownPayment = BillingPayment::where(['provider' => 'stripe', 'account' => $account, 'livemode' => $mode, 'provider_payment_id' => $pid])->first();
                    $projection = $knownPayment?->local_transaction_id ? Transaction::withoutGlobalScopes()->find($knownPayment->local_transaction_id) : null;
                    $projectionMismatch = ! $projection || $projection->status !== $this->transactionStatus($payment['status'] ?? 'unknown') || ProviderData::canonicalMoney((string) $projection->amount) !== ProviderData::canonicalMoney(ProviderData::decimal($this->paymentAmount($payment), $payment['currency'] ?? $invoice['currency'])) || strtoupper((string) $projection->currency) !== strtoupper($payment['currency'] ?? $invoice['currency']);
                    if (! $knownPayment || $projectionMismatch || $knownPayment->status !== ($payment['status'] ?? 'unknown') || (int) $knownPayment->amount_minor !== $this->paymentAmount($payment) || (int) $knownPayment->refunded_minor !== (int) ($payment['_refunded_minor'] ?? 0) || (bool) $knownPayment->disputed !== (bool) ($payment['_disputed'] ?? false)) {
                        $differences[] = ['kind' => 'payment', 'provider_id' => $pid, 'status' => $payment['status'] ?? 'unknown'];
                    }
                }
            }
            $notice = null;
            if ($before['status'] !== 'active' && $after['status'] === 'active') {
                $notice = 'activated';
            } elseif ($after['status'] === 'active' && $before['end_date'] && $after['end_date'] && strtotime($after['end_date']) > strtotime($before['end_date'])) {
                $notice = 'renewed';
            } elseif ($before['status'] === 'active' && $after['status'] !== 'active') {
                $notice = $after['status'] === 'canceled' ? 'canceled' : 'suspended';
            }
            $currentInvoice = collect($invoices)->firstWhere('id', ProviderData::id($live['latest_invoice'] ?? null));
            $providerNotice = match ($eventType) {
                'customer.subscription.trial_will_end' => $status === 'trialing' ? 'trial_ending' : null,
                'customer.subscription.pending_update_applied' => 'pending_update_applied',
                'customer.subscription.pending_update_expired' => 'pending_update_expired',
                'invoice.payment_failed' => ($currentInvoice && $currentInvoice['status'] !== 'paid') ? 'payment_failed' : null,
                'invoice.payment_action_required' => ($currentInvoice && $currentInvoice['status'] !== 'paid') ? 'action_required' : null,
                'invoice.finalization_failed' => ($currentInvoice && $currentInvoice['status'] !== 'paid') ? 'finalization_failed' : null,
                default => null,
            };
            if ($providerNotice && ! BillingStateChange::where('subscription_id', $subscription->id)->where('correlation_id', $correlation)->exists()) {
                $notice = $providerNotice;
                $differences[] = ['kind' => 'provider_notice', 'type' => $providerNotice];
            }
            $financialChanges = collect($differences)->contains(fn ($d) => $d['kind'] !== 'verification');
            $deferred = collect($invoices)->filter(fn ($i) => ! empty($i['_payments_deferred']))->count();
            $outcome = ['correlation_id' => $correlation, 'warnings' => ['payment_verification_deferred' => $deferred, 'manual_review' => $manualReview], 'subscription_id' => $subscription->id, 'stripe_id' => $id, 'stripe_status' => $status, 'from' => $before['status'], 'to' => $after['status'], 'action' => $differences ? ($dryRun ? 'would_repair' : 'repaired') : 'unchanged', 'changed' => ! $dryRun && $financialChanges, 'dry_run' => $dryRun, 'differences' => $differences, 'coverage' => $after, 'error' => null];
            if ($expectedHash !== null && ! hash_equals($expectedHash, hash('sha256', json_encode($differences, JSON_THROW_ON_ERROR)))) {
                throw new \RuntimeException('Provider evidence changed since preview; a fresh preview is required.');
            }
            if ($dryRun) {
                return $outcome;
            }
            if (! $financialChanges) {
                // Nothing financial changed (no differences, or only a refreshed payment verification
                // stamp): record the observation and the new stamp, and leave invoices and
                // transactions alone.
                DB::transaction(function () use ($subscription, $invoices, $account, $mode, $source, $correlation): void {
                    $subscription = $subscription->newQueryWithoutScopes()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
                    foreach ($invoices as $invoice) {
                        $known = BillingProviderInvoice::where(['provider' => 'stripe', 'account' => $account, 'livemode' => $mode, 'provider_invoice_id' => $invoice['id']])->first();
                        if ($known && ! empty($invoice['_payments_verified_at']) && data_get($known->snapshot, '_payments_verified_at') !== $invoice['_payments_verified_at']) {
                            $snapshot = $known->snapshot;
                            $snapshot['_payments_verified_at'] = $invoice['_payments_verified_at'];
                            $known->snapshot = $snapshot;
                            $known->save();
                        }
                    }
                    $metadata = $subscription->metadata ?? [];
                    data_set($metadata, 'billing_observation.verified_at', now()->toIso8601String());
                    data_set($metadata, 'billing_observation.source', $source);
                    data_set($metadata, 'billing_observation.correlation_id', $correlation);
                    $subscription->metadata = $metadata;
                    $subscription->save();
                });

                return $outcome;
            }
            DB::transaction(function () use ($subscription, $invoices, $mode, $account, $after, $source, $correlation, $actor, $before, $financialChanges, $notice) {
                $subscription->newQueryWithoutScopes()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
                foreach ($invoices as $invoice) {
                    $this->applyInvoice($subscription, $invoice, $account, $mode);
                }
                $subscription->refresh();
                $metadata = $subscription->metadata ?? [];
                data_set($metadata, 'billing_observation', array_merge($after, ['verified_at' => now()->toIso8601String(), 'source' => $source, 'correlation_id' => $correlation]));
                $subscription->fill(['status' => $after['status'], 'auto_renew' => $after['auto_renew'], 'end_date' => $after['end_date'], 'metadata' => $metadata])->save();
                BillingStateChange::create(['subscription_id' => $subscription->id, 'source' => $source, 'correlation_id' => $correlation, 'actor_id' => $actor, 'reason' => $financialChanges ? 'authoritative_provider_reconciliation' : 'provider_observation_refreshed', 'before' => $before, 'after' => array_merge($after, ['notice' => $notice]), 'mail_status' => $notice ? 'pending' : null, 'delivered_at' => $financialChanges ? null : now()]);
            });

            return $outcome;
        });
    }

    protected function state(Model $s): array
    {
        $o = data_get($s->metadata, 'billing_observation', []);

        return ['status' => $s->status, 'auto_renew' => (bool) $s->auto_renew, 'end_date' => $s->end_date?->utc()->format('c'), 'provider_status' => $o['provider_status'] ?? null, 'current_period_end' => $o['current_period_end'] ?? null, 'access_reason' => $o['access_reason'] ?? null, 'contract_prices' => $o['contract_prices'] ?? [], 'catalog_price' => $o['catalog_price'] ?? null, 'provider_paid_through' => $o['provider_paid_through'] ?? null, 'test_expiry_at' => $o['test_expiry_at'] ?? null];
    }

    protected function transactionStatus(string $status): string
    {
        return match ($status) {
            'succeeded' => 'completed','refunded' => 'refunded','partially_refunded' => 'partially_refunded','disputed' => 'disputed','canceled' => 'cancelled','requires_payment_method' => 'failed','requires_action' => 'action_required',default => 'pending'
        };
    }

    protected function paymentId(array $p): string
    {
        return ProviderData::id($p['latest_charge'] ?? null) ?: ProviderData::id(data_get($p, 'last_payment_error.charge')) ?: (string) $p['id'];
    }

    protected function paymentAmount(array $p): int
    {
        return (int) (($p['status'] ?? '') === 'succeeded' ? ($p['amount_received'] ?? $p['amount'] ?? 0) : ($p['amount'] ?? $p['amount_received'] ?? 0));
    }

    protected function legacyTransactions(int $invoiceId)
    {
        return Transaction::withoutGlobalScopes()->where('invoice_id', $invoiceId)->where('status', '!=', 'superseded')->where(fn ($q) => $q->where('provider_reference', 'like', 'cs\_%')->orWhere('provider_reference', 'like', 'sub\_%'))->get();
    }

    protected function localInvoice(Model $subscription, array $data, ?BillingProviderInvoice $evidence): ?Invoice
    {
        if ($evidence?->local_invoice_id) {
            $invoice = Invoice::withoutGlobalScopes()->find($evidence->local_invoice_id);
            if ($invoice && $invoice->subscription_id != $subscription->id) {
                throw new \RuntimeException('Local invoice belongs to another subscription.');
            }if ($invoice) {
                return $invoice;
            }
        }
        $linked = Invoice::withoutGlobalScopes()->where('subscription_id', $subscription->id)->where('metadata->providers->stripe->invoice_id', $data['id'])->get();
        if ($linked->count() > 1) {
            throw new \RuntimeException('Ambiguous provider invoice mapping; manual review required.');
        }
        if ($linked->count()) {
            return $linked->first();
        }
        if (($data['billing_reason'] ?? '') !== 'subscription_create') {
            return null;
        }
        $candidates = Invoice::withoutGlobalScopes()->where('subscription_id', $subscription->id)->get()->filter(fn ($i) => ! data_get($i->metadata, 'providers.stripe.invoice_id'));
        if ($candidates->count() > 1) {
            throw new \RuntimeException('Ambiguous initial invoice mapping; manual review required.');
        }

        return $candidates->first();
    }

    /**
     * Save a local projection only when the provider data really changed it.
     *
     * Money is compared canonically: the provider helper yields "89.97" while the database returns
     * "89.970000", and Eloquent treats those as different strings. Without this every reconcile pass
     * rewrote settled invoices and transactions and bumped their updated_at, which made old payments
     * look like they happened today. $evidence (raw provider payloads) is written alongside a real
     * change or on creation, but never on its own; the evidence tables already hold the snapshot.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $evidence
     */
    protected function saveIfChanged(Model $model, array $attributes, array $evidence = []): void
    {
        if ($model->exists) {
            foreach (['amount', 'amount_paid'] as $column) {
                if (array_key_exists($column, $attributes) && ProviderData::canonicalMoney((string) $model->getAttribute($column)) === ProviderData::canonicalMoney((string) $attributes[$column])) {
                    unset($attributes[$column]);
                }
            }
        }
        $model->fill($attributes);
        if (! $model->exists || $model->isDirty()) {
            $model->fill($evidence)->save();
        }
    }

    protected function applyInvoice(Model $subscription, array $data, string $account, bool $mode): void
    {
        $identity = ['provider' => 'stripe', 'account' => $account, 'livemode' => $mode, 'provider_invoice_id' => $data['id']];
        $evidence = BillingProviderInvoice::firstOrNew($identity);
        $local = $this->localInvoice($subscription, $data, $evidence);
        $local ??= new Invoice(['subscription_id' => $subscription->id]);
        if ($local->subscription_id != $subscription->id) {
            throw new \RuntimeException('Local invoice belongs to another subscription.');
        }
        $currency = (string) $data['currency'];
        $metadata = $local->metadata ?? [];
        data_set($metadata, 'providers.stripe.invoice_id', $data['id']);
        data_set($metadata, 'providers.stripe.invoice_status', $data['status']);
        data_set($metadata, 'providers.stripe.currency', $currency);
        $this->saveIfChanged($local, ['amount' => ProviderData::decimal((int) $data['amount_due'], $currency), 'amount_paid' => ProviderData::decimal((int) $data['amount_paid'], $currency), 'status' => match ($data['status']) {
            'paid' => 'paid','void','uncollectible' => 'failed',default => 'pending'
        }, 'metadata' => $metadata, 'due_date' => now()->setTimestamp((int) ($data['due_date'] ?? $data['created']))]);
        if (! $local->invoice_number) {
            $local->invoice_number = 'INV-'.now()->format('Ym').'-'.str_pad((string) $local->id, 6, '0', STR_PAD_LEFT);
            $local->saveQuietly();
        }
        [$start,$end] = ProviderData::servicePeriod($data);
        $evidence->fill(['subscription_id' => $subscription->id, 'local_invoice_id' => $local->id, 'status' => $data['status'], 'currency' => $currency, 'amount_due_minor' => $data['amount_due'], 'amount_paid_minor' => $data['amount_paid'], 'period_start' => $start ? gmdate('c', $start) : null, 'period_end' => $end ? gmdate('c', $end) : null, 'snapshot' => $data])->save();
        foreach ($data['_payments'] ?? [] as $p) {
            if (isset($p['currency']) && strtolower($p['currency']) !== strtolower($currency)) {
                throw new \RuntimeException('Payment currency differs from invoice currency.');
            }
            $pid = $this->paymentId($p);
            $payment = BillingPayment::firstOrNew(['provider' => 'stripe', 'account' => $account, 'livemode' => $mode, 'provider_payment_id' => $pid]);
            if ($payment->exists && $payment->subscription_id != $subscription->id) {
                throw new \RuntimeException('Payment belongs to another subscription.');
            }
            $transaction = $payment->local_transaction_id ? Transaction::withoutGlobalScopes()->find($payment->local_transaction_id) : Transaction::withoutGlobalScopes()->where('provider_reference', $pid)->where('invoice_id', $local->id)->first();
            if (! $transaction && Transaction::withoutGlobalScopes()->where('provider_reference', $pid)->exists()) {
                throw new \RuntimeException('Existing payment reference has an ambiguous invoice mapping.');
            }
            if ($transaction && ($transaction->invoice_id != $local->id || $transaction->user_id != $subscription->user_id)) {
                throw new \RuntimeException('Transaction ownership does not match the provider payment.');
            }
            $transaction ??= new Transaction(['invoice_id' => $local->id, 'user_id' => $subscription->user_id, 'payment_method_id' => PaymentMethod::get('stripe')?->id, 'provider_reference' => $pid]);
            $amount = $this->paymentAmount($p);
            $status = (string) ($p['status'] ?? 'unknown');
            $this->saveIfChanged($transaction, ['status' => $this->transactionStatus($status), 'amount' => ProviderData::decimal($amount, $p['currency'] ?? $currency), 'currency' => strtoupper($p['currency'] ?? $currency), 'metadata' => ['provider' => 'stripe', 'evidence' => 'provider_payment', 'provider_invoice_id' => $data['id']]], ['raw_callback' => json_encode($p, JSON_THROW_ON_ERROR)]);
            $payment->fill(['subscription_id' => $subscription->id, 'provider_invoice_id' => $evidence->id, 'local_transaction_id' => $transaction->id, 'status' => $status, 'currency' => $p['currency'] ?? $currency, 'amount_minor' => $amount, 'refunded_minor' => (int) ($p['_refunded_minor'] ?? 0), 'disputed' => (bool) ($p['_disputed'] ?? false), 'snapshot' => $p])->save();
        }
        if (! empty($data['_payments'])) {
            foreach ($this->legacyTransactions($local->id) as $legacy) {
                $metadata = $legacy->metadata ?? [];
                $metadata['original_status'] = $legacy->status;
                $metadata['evidence'] = 'legacy_lifecycle_telemetry';
                $metadata['superseded_by_provider_invoice'] = $data['id'];
                $legacy->update(['status' => 'superseded', 'metadata' => $metadata]);
            }
        }

    }
}
