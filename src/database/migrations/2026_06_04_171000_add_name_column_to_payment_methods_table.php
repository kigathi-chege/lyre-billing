<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('lyre.table_prefix') . 'payment_methods';

        if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'name')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) {
            $table->string('name')->nullable()->after('metadata');
        });
    }

    public function down(): void
    {
        $tableName = config('lyre.table_prefix') . 'payment_methods';

        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'name')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
