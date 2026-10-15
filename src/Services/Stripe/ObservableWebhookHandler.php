<?php

namespace Lyre\Billing\Services\Stripe;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Lyre\Billing\Jobs\ProcessBillingEvent;
use Lyre\Billing\Models\BillingProviderEvent;
use Stripe\Exception\SignatureVerificationException;
use Stripe\WebhookSignature;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class ObservableWebhookHandler extends WebhookEventHandler
{
    public function handle(Request $request): void
    {
        if (! config('billing.observability.enabled')) {
            parent::handle($request);

            return;
        }
        if (config('queue.default') === 'sync') {
            throw new \RuntimeException('Durable billing intake requires an asynchronous queue.');
        }
        $secrets = Client::webhookSecrets();
        if (! $secrets) {
            throw new \RuntimeException('Stripe webhook signing secret is not configured.');
        }
        $verified = false;
        foreach ($secrets as $secret) {
            try {
                WebhookSignature::verifyHeader($request->getContent(), (string) $request->header('Stripe-Signature', ''), $secret, 300);
                $verified = true;
                break;
            } catch (SignatureVerificationException) {
            }
        }
        if (! $verified) {
            throw new BadRequestHttpException('Stripe webhook signature is invalid.');
        }
        try {
            $raw = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new BadRequestHttpException('Invalid webhook JSON.', $e);
        }
        if (! is_array($raw) || empty($raw['id']) || empty($raw['type'])) {
            throw new BadRequestHttpException('Verified webhook has no event identifier/type.');
        }
        $type = str_starts_with($raw['type'], 'v1.') ? substr($raw['type'], 3) : $raw['type'];
        $event = BillingProviderEvent::firstOrCreate(['provider' => 'stripe', 'account' => $raw['account'] ?? $raw['context'] ?? 'platform', 'livemode' => (bool) ($raw['livemode'] ?? false), 'provider_event_id' => $raw['id']], ['event_type' => $type, 'api_version' => $raw['api_version'] ?? null, 'provider_created_at' => $raw['created'] ?? null, 'payload' => $raw, 'checksum' => hash('sha256', $request->getContent()), 'status' => 'received', 'correlation_id' => (string) Str::uuid(), 'received_at' => now()]);
        try {
            ProcessBillingEvent::dispatch($event->id)->afterCommit();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
