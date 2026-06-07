<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('lyre.table_prefix') . 'subscription_plan_billables';

        if (! Schema::hasTable($tableName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
            if (! Schema::hasColumn($tableName, 'usage_limit')) {
                $table->integer('usage_limit')->nullable()->comment('Included quota for this billable on the plan');
            }

            if (! Schema::hasColumn($tableName, 'unit_price')) {
                $table->decimal('unit_price', 12, 2)->nullable()->comment('Overage unit price for this plan billable');
            }
        });
    }

    public function down(): void
    {
        $tableName = config('lyre.table_prefix') . 'subscription_plan_billables';

        if (! Schema::hasTable($tableName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
            $columns = collect(['usage_limit', 'unit_price'])
                ->filter(fn (string $column) => Schema::hasColumn($tableName, $column))
                ->values()
                ->all();

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
