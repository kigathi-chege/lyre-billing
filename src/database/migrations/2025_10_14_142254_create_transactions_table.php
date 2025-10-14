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
        Schema::create('transactions', function (Blueprint $table) {
            basic_fields($table, 'transactions');
            $table->uuid('uuid')->unique();
            $table->string('status')->default('pending')->comment('The status of the transaction, pending, completed, failed, cancelled, refunded, etc');
            $table->decimal('amount', 20, 6);
            $table->string('provider_reference')->nullable();
            $table->string('currency')->default('KES');
            $table->text('raw_response')->nullable()->comment('The raw response from the payment provider');
            $table->text('raw_callback')->nullable()->comment('The raw callback from the payment provider');

            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_method_id')->constrained()->nullOnDelete();

            $table->index(['uuid']);
            $table->index(['status']);
            $table->index(['invoice_id']);
            $table->index(['user_id']);
            $table->index(['payment_method_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
