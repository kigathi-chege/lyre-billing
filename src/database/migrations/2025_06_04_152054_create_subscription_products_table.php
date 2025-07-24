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
        Schema::create('subscription_products', function (Blueprint $table) {
            basic_fields($table, 'subscription_products');
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->morphs('product');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_products');
    }
};
