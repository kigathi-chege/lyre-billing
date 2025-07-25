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
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->enum('provider', config('lyre-billing.providers', ['mpesa', 'paystack', 'stripe', 'paypal', 'bank_transfer']))->default('paypal')->comment('The payment provider');
            $table->jsonb('details')->nullable()->comment('Encrypted payment details in JSONB format');
            $table->boolean('is_default')->default(false)->comment('Indicates if this is the default payment method');

            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
