<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grace-days overrides for subscription coverage. After a subscription's coverage end,
 * access is retained (and expiry deferred) for the grace window while the provider's
 * dunning retries the renewal charge. Resolution order: subscriptions.grace_days →
 * subscription_plans.grace_days → config('billing.grace_days', 2). Both columns are
 * nullable (NULL = inherit the next level). Guarded so a host app that already added
 * subscriptions.grace_days is unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            if (! Schema::hasColumn('subscription_plans', 'grace_days')) {
                $table->unsignedInteger('grace_days')->nullable()->after('trial_days');
            }
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            if (! Schema::hasColumn('subscriptions', 'grace_days')) {
                $table->unsignedInteger('grace_days')->nullable()->after('auto_renew');
            }
        });
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            if (Schema::hasColumn('subscription_plans', 'grace_days')) {
                $table->dropColumn('grace_days');
            }
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            if (Schema::hasColumn('subscriptions', 'grace_days')) {
                $table->dropColumn('grace_days');
            }
        });
    }
};
