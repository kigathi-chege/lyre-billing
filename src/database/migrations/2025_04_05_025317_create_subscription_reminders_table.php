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
        Schema::create('subscription_reminders', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->enum('type', config('lyre-billing.subscription_reminder_types', ['renewal', 'payment_due', 'trial_end']))->default('renewal')->comment('The type of reminder');
            $table->dateTime('send_at')->nullable(false)->comment('The date and time when the reminder should be sent');
            $table->enum('status', config('lyre-billing.subscription_reminder_statuses', ['pending', 'sent']))->default('pending')->comment('The status of the reminder');

            $table->foreignId('subscription_id')->constrained('subscriptions')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_reminders');
    }
};
