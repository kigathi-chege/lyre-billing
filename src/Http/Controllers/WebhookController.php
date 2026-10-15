<?php

namespace Lyre\Billing\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Log;

class WebhookController extends BaseController
{
    public function __invoke(Request $request)
    {
        Log::info('billing.webhook.request_received', ['has_stripe_signature' => $request->hasHeader('Stripe-Signature')]);

        $provider = strtolower((string) ($request->query('provider') ?: $this->resolveProvider($request)));
        $handlerClass = config("billing.providers.{$provider}.webhook_handler");

        if ($handlerClass && class_exists($handlerClass)) {
            app($handlerClass)->handle($request);
        } else {
            throw new \RuntimeException('Billing webhook handler is not configured.');
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
