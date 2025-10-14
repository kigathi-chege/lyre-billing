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
        if (!Schema::hasTable('subscription_plans')) {
            Schema::create('subscription_plans', function (Blueprint $table) {
                $connection = Schema::getConnection();
                $driver = $connection->getDriverName();

                basic_fields($table, 'subscription_plans');

                $table->string('name');
                $table->decimal('price', 20, 6)->default(0.00);
                $table->string('billing_cycle')->default('monthly')->comment('The billing cycle of the subscription plan, per_minute, per_hour, per_day, per_week, monthly, quarterly, semi_annually, annually');
                $table->unsignedInteger('trial_days')->default(0);
                $table->{$driver === 'pgsql' ? 'jsonb' : 'json'}('features')->nullable()->comment('JSONB column to store plan-specific features');
                $table->string('status')->default('active');

                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('billable_id')->nullable()->constrained()->nullOnDelete();

                $table->index(['name']);
                $table->index(['status']);
                $table->index(['user_id']);
                $table->index(['billable_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
