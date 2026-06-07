<?php

namespace Lyre\Billing\Console\Commands;

use Lyre\Billing\Models\Subscription;
use Illuminate\Console\Command;
use Lyre\Billing\Services\SubscriptionLifecycleService;

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
        $lifecycleService = app(SubscriptionLifecycleService::class);

        $toMarkExpired = Subscription::where('end_date', '<', now())->where('status', 'active')->get();

        foreach ($toMarkExpired as $subscription) {
            $lifecycleService->expire($subscription);
        }

        $toNotifyBeforeExpiry = Subscription::where('end_date', '<', now()->addDays(2))->where('status', 'active')->get();

        foreach ($toNotifyBeforeExpiry as $subscription) {
            $lifecycleService->markRenewalDue($subscription);
        }
    }
}
