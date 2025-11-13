<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $prefix = config('lyre.table_prefix');
        $tableName = $prefix . 'subscription_plans';

        if (!Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table) use ($tableName, $prefix) {
                $connection = Schema::getConnection();
                $driver = $connection->getDriverName();

                basic_fields($table, $tableName);

                $table->string('name');
                $table->decimal('price', 20, 6)->default(0.00);
                $table->string('currency', 3)->comment('Currency of the price')->default('KES');
                $table->string('billing_cycle')->default('monthly')->comment('The billing cycle of the subscription plan, per_minute, per_hour, per_day, per_week, monthly, quarterly, semi_annually, annually');
                $table->unsignedInteger('trial_days')->default(0);
                $table->string('status')->default('active');

                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

                $table->index(['name']);
                $table->index(['status']);
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
        $tableName = $prefix . 'subscription_plans';

        Schema::dropIfExists($tableName);
    }
};
