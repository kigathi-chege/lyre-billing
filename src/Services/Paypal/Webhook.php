<?php

namespace Lyre\Billing\Services\Paypal;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Webhook
{
    /**
     * Create a PayPal webhook.
     *
     * @param string $webhookUrl The URL to receive PayPal events.
     * @param array|string $events Array of event names or "*" to subscribe to all.
     * @return array The response body or error details.
     */
    public static function createWebhook(string $webhookUrl, array|string $events): array
    {
        $eventTypes = $events === '*'
            ? [['name' => '*']]
            : collect($events)->map(fn($name) => ['name' => $name])->toArray();

        $token = Client::getOauthToken();

        $response = Http::withToken($token)
            ->post(config('services.paypal.base_uri') . "/v1/notifications/webhooks", [
                'url' => $webhookUrl,
                'event_types' => $eventTypes,
            ]);

        if ($response->successful()) {
            return $response->json();
        }

        Log::error('PayPal Create Webhook Error', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        throw new \Exception('PayPal webhook creation failed.');
    }

    public static function listWebhooks(): array
    {
        $response = Http::withToken(Client::getOauthToken())
            ->get(config('services.paypal.base_uri') . "/v1/notifications/webhooks");

        if ($response->successful()) {
            return $response->json();
        }

        Log::error('PayPal List Webhooks Error', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        throw new \Exception('PayPal list webhooks failed.');
    }

    public static function getWebhook(string $webhookId): array
    {
        $response = Http::withToken(Client::getOauthToken())
            ->get(config('services.paypal.base_uri') . "/v1/notifications/webhooks/{$webhookId}");

        if ($response->successful()) {
            return $response->json();
        }

        Log::error('PayPal Get Webhook Details Error', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        throw new \Exception('PayPal Get Webhook Details failed.');
    }

    /**
     * Update a PayPal webhook using JSON Patch.
     *
     * @param string $webhookId The ID of the webhook to update.
     * @param array $operations An array of JSON Patch operations.
     * @return array The response body or error details.
     *
     * Example $operations:
     * [
     *     ['op' => 'replace', 'path' => '/url', 'value' => 'https://your.site/webhook'],
     *     ['op' => 'replace', 'path' => '/event_types', 'value' => [['name' => 'BILLING.SUBSCRIPTION.CANCELLED']]]
     * ]
     *
     * How to use:
     *
     * $ops = [
     *      Client::makePatchOp('replace', '/url', 'https://yourapp.com/new_webhook'),
     *      Client::makePatchOp('replace', '/event_types', [
     *          ['name' => 'PAYMENT.SALE.REFUNDED'],
     *          ['name' => 'BILLING.SUBSCRIPTION.CANCELLED']
     *      ]),
     *  ];
     *
     *  $response = Webhook::updateWebhook('0EH40505U7160970P', $ops);
     *
     */
    public static function updateWebhook(string $webhookId, array $operations): array
    {
        $response = Http::withToken(Client::getOauthToken())
            ->withHeaders(['Content-Type' => 'application/json'])
            ->patch(config('services.paypal.base_uri') . "/v1/notifications/webhooks/{$webhookId}", $operations);

        if ($response->successful()) {
            return $response->json();
        }

        Log::error('PayPal Update Webhook Error', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        throw new \Exception('PayPal Update Webhook failed.');
    }

    public function deleteWebhook(string $webhookId): bool
    {
        $url = config('services.paypal.base_uri') . "/v1/notifications/webhooks/{$webhookId}";

        $response = Http::withToken(Client::getOauthToken())
            ->withHeaders(['Content-Type' => 'application/json'])
            ->delete($url);

        return $response->status() === 204;
    }
}
