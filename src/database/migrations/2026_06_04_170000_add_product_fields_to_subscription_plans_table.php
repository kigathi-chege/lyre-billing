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
            $connection = Schema::getConnection();
            $driver = $connection->getDriverName();

            if (! Schema::hasColumn($tableName, 'features')) {
                $table->{$driver === 'pgsql' ? 'jsonb' : 'json'}('features')->nullable()
                    ->comment('JSON column to store plan-specific features');
            }

            if (! Schema::hasColumn($tableName, 'product_type')) {
                $table->string('product_type')->nullable();
            }

            if (! Schema::hasColumn($tableName, 'product_id')) {
                $table->unsignedBigInteger('product_id')->nullable();
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
            if (Schema::hasColumn($tableName, 'product_type') && Schema::hasColumn($tableName, 'product_id')) {
                $table->dropColumn(['product_type', 'product_id']);
            }

            if (Schema::hasColumn($tableName, 'features')) {
                $table->dropColumn('features');
            }
        });
    }
};
