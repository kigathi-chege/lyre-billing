<?php

namespace Lyre\Billing\Http\Controllers;

use Lyre\Billing\Models\Subscription;
use Lyre\Billing\Contracts\SubscriptionRepositoryInterface;
use Lyre\Controller;

class SubscriptionController extends Controller
{
    public function __construct(
        SubscriptionRepositoryInterface $modelRepository
    ) {
        $model = new Subscription();
        $modelConfig = $model->generateConfig();
        parent::__construct($modelConfig, $modelRepository);
    }

    public function approved(string $subscription)
    {
        return curate_response(
            true,
            "Subscription Approved",
            // NOTE: Kigathi - June 6 2025 - this is commented out in favor of the webhook
            // $this->modelRepository->approved($subscription),
            [],
            get_response_code("get-{$this->modelNamePlural}")
        );
    }
}
