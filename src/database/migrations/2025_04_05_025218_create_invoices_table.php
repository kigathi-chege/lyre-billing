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
        if (!Schema::hasTable('invoices')) {
            Schema::create('invoices', function (Blueprint $table) {
                $table->id();
                $table->timestamps();

                $table->decimal('amount', 10, 2)->default(0.00)->comment('The total amount of the invoice');
                $table->enum('status', ['paid', 'pending', 'failed'])->default('pending')->comment('The status of the invoice');
                $table->dateTime('due_date')->nullable(false)->comment('The due date for the invoice payment');

                $table->foreignId('subscription_id')->constrained('subscriptions')->onDelete('cascade');
            });
        }

        if (!Schema::hasColumn('invoices', 'amount_paid')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->decimal('amount_paid', 10, 2)->default(0.00)->comment('The total amount paid by the client');
            });
        }

        if (Schema::hasColumn('invoices', 'due_date')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dateTime('due_date')->nullable()->comment('The due date for the invoice payment')->change();
            });
        }

        if (!Schema::hasColumn('invoices', 'invoice_number')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->string('invoice_number')->unique()->nullable()->comment('The unique invoice number');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
