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
        $tableName = $prefix . 'subscription_plan_billables';

        if (!Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table) use ($prefix) {
                $table->id();
                $table->foreignId('subscription_plan_id')
                    ->constrained($prefix . 'subscription_plans')
                    ->cascadeOnDelete();
                $table->foreignId('billable_id')
                    ->constrained($prefix . 'billables')
                    ->cascadeOnDelete();
                $table->integer('order')->default(0)->comment('Order of the billable in the subscription plan');
                $table->timestamps();
                $table->unique(['subscription_plan_id', 'billable_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $prefix = config('lyre.table_prefix');
        $tableName = $prefix . 'subscription_plan_billables';

        Schema::dropIfExists($tableName);
    }
};
