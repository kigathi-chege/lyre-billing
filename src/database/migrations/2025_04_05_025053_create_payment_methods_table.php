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
        $tableName = $prefix . 'payment_methods';

        if (!Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table) use ($tableName) {
                $connection = Schema::getConnection();
                $driver = $connection->getDriverName();

                basic_fields($table, $tableName);

                $table->string('name');
                $table->{$driver === 'pgsql' ? 'jsonb' : 'json'}('details')->nullable()->comment('Payment method details, e.g., secret key, public key, etc');
                $table->boolean('is_default')->default(false)->comment('Indicates if this is the default payment method');

                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

                $table->index(['name']);
                $table->index(['is_default']);
                $table->index(['user_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $prefix = config('lyre.table_prefix');
        $tableName = $prefix . 'payment_methods';

        Schema::dropIfExists($tableName);
    }
};
