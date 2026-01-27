<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $prefix = config('lyre.table_prefix');
        $tableName = $prefix . 'transactions';

        if (!Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table) use ($tableName, $prefix) {
                basic_fields($table, $tableName);
                $table->uuid('uuid')->unique();
                $table->string('status')->default('pending')->comment('The status of the transaction, pending, completed, failed, cancelled, refunded, etc');
                $table->decimal('amount', 20, 6);
                $table->string('provider_reference')->nullable();
                $table->string('currency')->default('KES');
                $table->text('raw_response')->nullable()->comment('The raw response from the payment provider');
                $table->text('raw_callback')->nullable()->comment('The raw callback from the payment provider');
                $table->text('raw_request')->nullable()->comment('The raw request to the payment provider');

                $table->foreignId('invoice_id')->nullable()->constrained($prefix . 'invoices')->nullOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('payment_method_id')->constrained($prefix . 'payment_methods')->nullOnDelete();
                $table->string('order_reference')->nullable()->comment('Reference to Commerce Order for order payments');

                $table->index(['uuid']);
                $table->index(['status']);
                $table->index(['invoice_id']);
                $table->index(['user_id']);
                $table->index(['payment_method_id']);
                $table->index(['order_reference']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $prefix = config('lyre.table_prefix');
        $tableName = $prefix . 'transactions';

        Schema::dropIfExists($tableName);
    }
};
