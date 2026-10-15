<?php

namespace Lyre\Billing\Console\Commands;

use Illuminate\Console\Command;
use Lyre\Billing\Jobs\ProcessBillingEvent;
use Lyre\Billing\Models\BillingProviderEvent;

class DispatchBillingEvents extends Command
{
    protected $signature = 'billing:dispatch-events {--limit=100}';

    protected $description = 'Recover durably received, retrying or abandoned billing events.';

    public function handle(): int
    {
        if (! config('billing.observability.enabled')) {
            return self::SUCCESS;
        }
        BillingProviderEvent::whereIn('status', ['received', 'retrying', 'processing'])->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<=', now()))->orderBy('id')->limit(max(1, min(1000, (int) $this->option('limit'))))->get()->each(fn ($event) => ProcessBillingEvent::dispatch($event->id));

        return self::SUCCESS;
    }
}
