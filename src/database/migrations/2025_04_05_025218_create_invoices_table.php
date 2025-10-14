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
        $tableName = $prefix . 'invoices';

        if (!Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table) use ($tableName, $prefix) {
                basic_fields($table, $tableName);

                $table->decimal('amount', 10, 2)->default(0.00)->comment('The total amount of the invoice');
                $table->decimal('amount_paid', 20, 6)->default(0.00)->comment('The total amount paid by the client');
                $table->string('status')->default('pending')->comment('The status of the invoice, paid, pending, failed');
                $table->dateTime('due_date')->nullable(false)->comment('The due date for the invoice payment');
                $table->string('invoice_number')->unique()->nullable()->comment('The unique invoice number');

                $table->foreignId('subscription_id')->constrained($prefix . 'subscriptions')->nullOnDelete();

                $table->index(['status']);
                $table->index(['subscription_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $prefix = config('lyre.table_prefix');
        $tableName = $prefix . 'invoices';

        Schema::dropIfExists($tableName);
    }
};
