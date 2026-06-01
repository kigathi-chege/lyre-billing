<?php

namespace Lyre\Billing\Observers;

use Lyre\Observer;

class InvoiceObserver extends Observer
{
    public function created($model): void
    {
        if (! $model->invoice_number) {
            $model->invoice_number = 'INV-' . now()->format('Ym') . '-' . str_pad((string) $model->id, 6, '0', STR_PAD_LEFT);
            $model->saveQuietly();
        }

        parent::created($model);
    }
}
