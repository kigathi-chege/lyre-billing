<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('lyre.table_prefix') . 'subscription_plans';

        if (! Schema::hasTable($tableName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
            $driver = Schema::getConnection()->getDriverName();

            if (! Schema::hasColumn($tableName, 'kind')) {
                $table->string('kind')->default('main')->comment('main|per_exam');
            }

            if (! Schema::hasColumn($tableName, 'entitlement_mode')) {
                $table->string('entitlement_mode')->nullable()->default('fixed')->comment('fixed|quota');
            }

            if (! Schema::hasColumn($tableName, 'visibility')) {
                $table->string('visibility')->default('public')->comment('public|hidden');
            }

            if (! Schema::hasColumn($tableName, 'entitlements_config')) {
                $table->{$driver === 'pgsql' ? 'jsonb' : 'json'}('entitlements_config')
                    ->nullable()
                    ->comment('Structured plan entitlements and quota rules');
            }
        });
    }

    public function down(): void
    {
        $tableName = config('lyre.table_prefix') . 'subscription_plans';

        if (! Schema::hasTable($tableName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
            $columns = collect(['kind', 'entitlement_mode', 'visibility', 'entitlements_config'])
                ->filter(fn (string $column) => Schema::hasColumn($tableName, $column))
                ->values()
                ->all();

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
