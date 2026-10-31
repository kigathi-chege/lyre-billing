<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Checkout creates the pending invoice before the provider has issued its own, so there is no due date yet; the
 * provider sync fills it in later. The original migration made the column NOT NULL, which made the first checkout
 * on a freshly migrated database fail with a not-null violation. Idempotent: a no-op where it is already nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('lyre.table_prefix').'invoices';

        if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'due_date')) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dateTime('due_date')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: restoring NOT NULL would fail on invoices created while it was nullable.
    }
};
