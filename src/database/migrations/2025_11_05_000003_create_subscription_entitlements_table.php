<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('lyre.table_prefix');
        $tableName = $prefix . 'subscription_entitlements';
        $morphIndexName = $tableName . '_entitlable_type_entitlable_id_index';
        $explicitMorphIndexName = 'sub_entitlements_entitlable_lookup_index';

        if (! Schema::hasTable($tableName)) {
            // A previous failed run may have left the default morph index name behind in Postgres.
            // Remove it before recreating the table with an explicit, stable index name.
            DB::statement(sprintf('DROP INDEX IF EXISTS "%s"', $morphIndexName));

            Schema::create($tableName, function (Blueprint $table) use ($tableName, $prefix, $explicitMorphIndexName) {
                basic_fields($table, $tableName);

                $table->foreignId('subscription_id')->constrained($prefix . 'subscriptions')->cascadeOnDelete();
                $table->morphs('entitlable', $explicitMorphIndexName);
                $table->string('source')->default('manual')->comment('manual|plan|compat_legacy_product');

                $table->unique(['subscription_id', 'entitlable_type', 'entitlable_id'], 'sub_entitlements_unique');
                $table->index(['subscription_id']);
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
