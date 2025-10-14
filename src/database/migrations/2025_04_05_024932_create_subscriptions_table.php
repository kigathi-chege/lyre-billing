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
        $tableName = $prefix . 'subscriptions';

        if (!Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table) use ($tableName, $prefix) {
                basic_fields($table, $tableName);

                $table->string('status')->default('pending')->comment('The status of the subscription, pending, active, paused, canceled, expired');
                $table->dateTime('start_date')->nullable(false)->comment('The date when the subscription starts');
                $table->dateTime('end_date')->nullable()->comment('The date when the current subscription term ends');
                $table->boolean('auto_renew')->default(true)->comment('Indicates if the subscription will auto-renew at the end of the term');

                $table->string('address_line_1')->nullable()->comment('The first line of the address');
                $table->string('address_line_2')->nullable()->comment('The second line of the address');
                $table->string('admin_area_2')->nullable()->comment('The city/town of the address');
                $table->string('admin_area_1')->nullable()->comment('The state/province of the address');
                $table->string('postal_code')->nullable()->comment('The postal code of the address');
                $table->string('country_code')->nullable()->comment('The country code of the address');
                $table->string('phone')->nullable()->comment('The phone number of the address');
                $table->string('email')->nullable()->comment('The email address of the address');
                $table->string('name')->nullable()->comment('The name of the address');
                $table->string('company')->nullable()->comment('The company name of the address');
                $table->string('tax_id')->nullable()->comment('The tax id of the address');
                $table->string('tax_id_type')->nullable()->comment('The tax id type of the address');
                $table->string('tax_id_number')->nullable()->comment('The tax id number of the address');

                $table->foreignId('user_id')->constrained()->nullOnDelete();
                $table->foreignId('subscription_plan_id')->constrained($prefix . 'subscription_plans')->nullOnDelete();

                $table->index(['status']);
                $table->index(['user_id']);
                $table->index(['subscription_plan_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $prefix = config('lyre.table_prefix');
        $tableName = $prefix . 'subscriptions';

        Schema::dropIfExists($tableName);
    }
};
