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
        Schema::create('product_entities', function (Blueprint $table) {
            basic_fields($table, 'product_entities');
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->morphs('entity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_entities');
    }
};
