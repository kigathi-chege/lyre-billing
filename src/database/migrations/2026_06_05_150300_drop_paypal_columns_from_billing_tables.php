<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('lyre.table_prefix');
        $subscriptionTable = $prefix . 'subscriptions';
        $planTable = $prefix . 'subscription_plans';

        if (Schema::hasTable($subscriptionTable)) {
            Schema::table($subscriptionTable, function (Blueprint $table) use ($subscriptionTable) {
                $columns = collect(['paypal_id', 'link', 'start_time'])
                    ->filter(fn (string $column) => Schema::hasColumn($subscriptionTable, $column))
                    ->values()
                    ->all();

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }

        if (Schema::hasTable($planTable)) {
            Schema::table($planTable, function (Blueprint $table) use ($planTable) {
                $columns = collect(['paypal_product_id', 'paypal_plan_id', 'link'])
                    ->filter(fn (string $column) => Schema::hasColumn($planTable, $column))
                    ->values()
                    ->all();

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
    }

    public function down(): void
    {
        $prefix = config('lyre.table_prefix');
        $subscriptionTable = $prefix . 'subscriptions';
        $planTable = $prefix . 'subscription_plans';

        if (Schema::hasTable($subscriptionTable)) {
            Schema::table($subscriptionTable, function (Blueprint $table) use ($subscriptionTable) {
                if (! Schema::hasColumn($subscriptionTable, 'paypal_id')) {
                    $table->string('paypal_id')->nullable();
                }

                if (! Schema::hasColumn($subscriptionTable, 'link')) {
                    $table->string('link')->nullable();
                }

                if (! Schema::hasColumn($subscriptionTable, 'start_time')) {
                    $table->dateTime('start_time')->nullable();
                }
            });
        }

        if (Schema::hasTable($planTable)) {
            Schema::table($planTable, function (Blueprint $table) use ($planTable) {
                if (! Schema::hasColumn($planTable, 'paypal_product_id')) {
                    $table->string('paypal_product_id')->nullable();
                }

                if (! Schema::hasColumn($planTable, 'paypal_plan_id')) {
                    $table->string('paypal_plan_id')->nullable();
                }

                if (! Schema::hasColumn($planTable, 'link')) {
                    $table->string('link')->nullable();
                }
            });
        }
    }
};
