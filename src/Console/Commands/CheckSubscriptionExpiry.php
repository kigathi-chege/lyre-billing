<?php

namespace Lyre\Billing\Console\Commands;

use Lyre\Billing\Jobs\SendEmails;
use Lyre\Billing\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class CheckSubscriptionExpiry extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:check-subscription-expiry';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This command checks which subscriptions have expired, marks them as expired, and sends a notification to the user';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $toMarkExpired = Subscription::with(['subscriptionReminders'])->where('end_date', '<', now())->where('status', 'active')->get();

        foreach ($toMarkExpired as $subscription) {
            $subscription->status = 'expired';
            $subscription->save();

            SendEmails::dispatch(
                email: $subscription->user->email,
                subject: 'Subscription Expiry',
                view: 'email.subscriptions.expired',
                data: [
                    'name' => $subscription->user->name,
                    'buttonText' => 'Renew Now',
                    'buttonLink' => config('services.paypal.base_uri') . "/billing/subscriptions/{$subscription->paypal_id}/capture"
                ]
            );
        }

        $toNotifyBeforeExpiry = Subscription::where('end_date', '<', now()->addDays(2))->where('status', 'active')->get();

        foreach ($toNotifyBeforeExpiry as $subscription) {
            SendEmails::dispatch(
                email: $subscription->user->email,
                subject: 'Subscription Expiry',
                view: 'email.subscriptions.expiring',
                data: [
                    'name' => $subscription->user->name,
                    'buttonText' => 'Renew Now',
                    'buttonLink' => config('services.paypal.base_uri') . "/billing/subscriptions/{$subscription->paypal_id}/capture"
                ]
            );
        }
    }
}
