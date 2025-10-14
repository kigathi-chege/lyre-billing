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
        $tableName = $prefix . 'billable_items';

        if (!Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table) use ($tableName, $prefix) {
                basic_fields($table, $tableName);
                $table->string('name')->nullable();
                $table->string("pricing_model")->default('free')->comment('The pricing model of the product, free, fixed, usage_based');
                $table->string('status')->default('active');
                $table->morphs('item');

                $table->foreignId('billable_id')->constrained($prefix . 'billables')->cascadeOnDelete();

                $table->index(['name']);
                $table->index(['status']);
                $table->index(['item']);
                $table->index(['billable_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $prefix = config('lyre.table_prefix');
        $tableName = $prefix . 'billable_items';

        Schema::dropIfExists($tableName);
    }
};
