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

                $table->string("item_type")->nullable();
                $table->string("item_id")->nullable();
                $table->index(["item_type", "item_id"]);

                $table->integer('usage_limit')->nullable()->comment('max usage per period for quota-based plans');
                $table->decimal('unit_price', 10, 2)->nullable()->comment('for usage-based billing');
                $table->string('currency', 3)->nullable()->comment('currency for usage-based billing');
                $table->string('reset_period')->nullable()->comment('reset period for billable usage, null for no reset');

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
