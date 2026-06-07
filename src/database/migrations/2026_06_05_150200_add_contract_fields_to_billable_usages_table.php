<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('lyre.table_prefix');
        $tableName = $prefix . 'billable_usages';
        $subscriptionTable = $prefix . 'subscriptions';

        if (! Schema::hasTable($tableName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName, $subscriptionTable) {
            $driver = Schema::getConnection()->getDriverName();

            if (! Schema::hasColumn($tableName, 'quantity')) {
                $table->decimal('quantity', 20, 6)->default(0)->comment('Measured quantity consumed');
            }

            if (! Schema::hasColumn($tableName, 'subscription_id')) {
                $table->foreignId('subscription_id')
                    ->nullable()
                    ->constrained($subscriptionTable)
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn($tableName, 'metadata')) {
                $table->{$driver === 'pgsql' ? 'jsonb' : 'json'}('metadata')->nullable();
            }
        });
    }

    public function down(): void
    {
        $tableName = config('lyre.table_prefix') . 'billable_usages';

        if (! Schema::hasTable($tableName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
            if (Schema::hasColumn($tableName, 'subscription_id')) {
                $table->dropConstrainedForeignId('subscription_id');
            }

            $columns = collect(['quantity', 'metadata'])
                ->filter(fn (string $column) => Schema::hasColumn($tableName, $column))
                ->values()
                ->all();

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
