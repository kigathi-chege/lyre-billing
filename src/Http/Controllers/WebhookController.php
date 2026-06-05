<?php

namespace Lyre\Billing\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

class WebhookController extends BaseController
{
    public function __invoke(Request $request)
    {
        \Illuminate\Support\Facades\Log::info("WEBHOOK", [$request->all()]);

        $provider = strtolower((string) ($request->query('provider') ?: $this->resolveProvider($request)));
        $handlerClass = config("billing.providers.{$provider}.webhook_handler");

        if ($handlerClass && class_exists($handlerClass)) {
            app($handlerClass)->handle($request);
        }

        return true;
    }

    protected function resolveProvider(Request $request): string
    {
        if ($request->header('Stripe-Signature')) {
            return 'stripe';
        }

        if ($request->has('event_type')) {
            return 'paypal';
        }

        return (string) config('billing.subscriptions.provider', 'paypal');
    }
}
