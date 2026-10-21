<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-plan settings for an included billable that do not fit usage_limit / unit_price:
 * on_exhaustion (upsell | overage), per_resource_limit and a reset_period override.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('lyre.table_prefix').'subscription_plan_billables';

        if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, 'metadata')) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->json('metadata')->nullable();
            });
        }
    }

    public function down(): void
    {
        $tableName = config('lyre.table_prefix').'subscription_plan_billables';

        if (Schema::hasColumn($tableName, 'metadata')) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('metadata');
            });
        }
    }
};
