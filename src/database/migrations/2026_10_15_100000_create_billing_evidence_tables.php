<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('lyre.table_prefix', '');
        Schema::create($prefix.'billing_provider_events', function (Blueprint $t) {
            $t->id();
            $t->string('provider');
            $t->string('account')->default('platform');
            $t->boolean('livemode');
            $t->string('provider_event_id');
            $t->unsignedInteger('generation')->default(0);
            $t->string('event_type');
            $t->string('api_version')->nullable();
            $t->bigInteger('provider_created_at')->nullable();
            $t->text('payload');
            $t->string('checksum', 64);
            $t->string('status')->default('received')->index();
            $t->string('reason')->nullable();
            $t->unsignedBigInteger('subscription_id')->nullable()->index();
            $t->string('correlation_id');
            $t->timestampTz('received_at');
            $t->timestampTz('completed_at')->nullable();
            $t->timestampTz('lease_until')->nullable();
            $t->string('lease_token')->nullable();
            $t->timestampsTz();
            $t->unique(['provider', 'account', 'livemode', 'provider_event_id'], 'billing_event_identity');
        });
        Schema::create($prefix.'billing_event_attempts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('event_id')->index();
            $t->unsignedInteger('generation')->default(0);
            $t->string('status');
            $t->timestampTz('started_at');
            $t->timestampTz('finished_at')->nullable();
            $t->string('worker');
            $t->string('release')->nullable();
            $t->text('error')->nullable();
            $t->timestampsTz();
        });
        Schema::create($prefix.'billing_provider_invoices', function (Blueprint $t) {
            $t->id();
            $t->string('provider');
            $t->string('account');
            $t->boolean('livemode');
            $t->string('provider_invoice_id');
            $t->unsignedBigInteger('subscription_id')->index();
            $t->unsignedBigInteger('local_invoice_id')->nullable();
            $t->string('status');
            $t->string('currency', 3);
            $t->bigInteger('amount_due_minor');
            $t->bigInteger('amount_paid_minor');
            $t->timestampTz('period_start')->nullable();
            $t->timestampTz('period_end')->nullable();
            $t->text('snapshot');
            $t->timestampsTz();
            $t->unique(['provider', 'account', 'livemode', 'provider_invoice_id'], 'billing_invoice_identity');
        });
        Schema::create($prefix.'billing_payments', function (Blueprint $t) {
            $t->id();
            $t->string('provider');
            $t->string('account');
            $t->boolean('livemode');
            $t->string('provider_payment_id');
            $t->unsignedBigInteger('subscription_id')->index();
            $t->unsignedBigInteger('provider_invoice_id')->index();
            $t->unsignedBigInteger('local_transaction_id')->nullable();
            $t->string('status');
            $t->string('currency', 3);
            $t->bigInteger('amount_minor');
            $t->bigInteger('refunded_minor')->default(0);
            $t->boolean('disputed')->default(false);
            $t->text('snapshot');
            $t->timestampsTz();
            $t->unique(['provider', 'account', 'livemode', 'provider_payment_id'], 'billing_payment_identity');
        });
        Schema::create($prefix.'billing_state_changes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('subscription_id')->index();
            $t->string('source');
            $t->string('correlation_id')->index();
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('reason');
            $t->json('before');
            $t->json('after');
            $t->timestampTz('delivered_at')->nullable()->index();
            $t->string('mail_status')->nullable()->index();
            $t->unsignedInteger('mail_attempts')->default(0);
            $t->timestampTz('mail_sent_at')->nullable();
            $t->text('mail_last_error')->nullable();
            $t->timestampsTz();
        });
        Schema::create($prefix.'billing_reconciliation_runs', function (Blueprint $t) {
            $t->id();
            $t->string('status')->index();
            $t->boolean('dry_run');
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->unsignedBigInteger('subscription_id')->nullable()->index();
            $t->string('correlation_id');
            $t->json('summary')->nullable();
            $t->timestampTz('started_at');
            $t->timestampTz('finished_at')->nullable();
            $t->timestampsTz();
        });
    }

    public function down(): void
    {
        foreach (['billing_reconciliation_runs', 'billing_state_changes', 'billing_payments', 'billing_provider_invoices', 'billing_event_attempts', 'billing_provider_events'] as $table) {
            Schema::dropIfExists(config('lyre.table_prefix', '').$table);
        }
    }
};
