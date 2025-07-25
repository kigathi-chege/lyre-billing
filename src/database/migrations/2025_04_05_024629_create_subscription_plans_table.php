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
                $table->id();
                $table->timestamps();

                $table->string('name');
                $table->decimal('price', 10, 2)->default(0.00);
                $table->enum('billing_cycle', config('lyre-billing.billing_cycles', ['per_minute', 'per_hour', 'per_day', 'per_week', 'monthly', 'quarterly', 'semi_annually', 'annually']))->default('monthly');
                $table->unsignedInteger('trial_days')->default(0);
                $table->jsonb('features')->nullable()->comment('JSONB column to store plan-specific features');

                $table->morphs('product');
            });
        }

        if (!Schema::hasColumn('subscription_plans', 'status')) {
            Schema::table('subscription_plans', function (Blueprint $table) {
                $table->enum('status', ['active', 'inactive'])->default('active');
            });
        }

        if (!Schema::hasColumn('subscription_plans', 'paypal_product_id')) {
            Schema::table('subscription_plans', function (Blueprint $table) {
                $table->string('paypal_product_id')->nullable();
            });
        }

        if (!Schema::hasColumn('subscription_plans', 'paypal_plan_id')) {
            Schema::table('subscription_plans', function (Blueprint $table) {
                $table->string('paypal_plan_id')->nullable();
            });
        }

        if (!Schema::hasColumn('subscription_plans', 'link')) {
            Schema::table('subscription_plans', function (Blueprint $table) {
                $table->string('link')->nullable();
                $table->string('slug')->nullable()->unique()->index();
                $table->text('description')->nullable();
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
