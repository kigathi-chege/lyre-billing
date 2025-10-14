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
        Schema::create('billable_items', function (Blueprint $table) {
            basic_fields($table, 'billable_items');
            $table->string('name')->nullable();
            $table->string("pricing_model")->default('free')->comment('The pricing model of the product, free, fixed, usage_based');
            $table->string('status')->default('active');
            $table->morphs('item');

            $table->foreignId('billable_id')->constrained()->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billable_items');
    }
};
