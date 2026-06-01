<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('lyre.table_prefix');
        $tableName = $prefix . 'subscription_entitlements';

        if (! Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table) use ($tableName, $prefix) {
                basic_fields($table, $tableName);

                $table->foreignId('subscription_id')->constrained($prefix . 'subscriptions')->cascadeOnDelete();
                $table->morphs('entitlable');
                $table->string('source')->default('manual')->comment('manual|plan|compat_legacy_product');

                $table->unique(['subscription_id', 'entitlable_type', 'entitlable_id'], 'sub_entitlements_unique');
                $table->index(['subscription_id']);
                $table->index(['entitlable_type', 'entitlable_id']);
                $table->index(['source']);
            });
        }
    }

    public function down(): void
    {
        $prefix = config('lyre.table_prefix');
        $tableName = $prefix . 'subscription_entitlements';

        Schema::dropIfExists($tableName);
    }
};
