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
        if (!Schema::hasTable('subscriptions')) {
            Schema::create('subscriptions', function (Blueprint $table) {
                $table->id();
                $table->timestamps();

                $table->enum('status', config('services.paypal.subscription_status'))->default('pending');
                $table->dateTime('start_date')->nullable(false)->comment('The date when the subscription starts');
                $table->dateTime('end_date')->nullable()->comment('The date when the current subscription term ends');
                $table->boolean('auto_renew')->default(true)->comment('Indicates if the subscription will auto-renew at the end of the term');

                $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
                $table->foreignId('subscription_plan_id')->constrained('subscription_plans')->onDelete('cascade');
            });
        }

        if (!Schema::hasColumn('subscriptions', 'paypal_id')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->string('paypal_id')->nullable();
            });
        }

        if (Schema::hasColumn('subscriptions', 'status')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->enum('status', config('services.paypal.subscription_status'))->default('pending')->change();
            });
        }

        if (!Schema::hasColumn('subscriptions', 'link')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->string('link')->nullable();
                $table->text('description')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
