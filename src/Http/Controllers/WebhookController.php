<?php

namespace Lyre\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use Lyre\Jobs\SendEmails;
use Lyre\Billing\Models\Invoice;
use Lyre\Billing\Contracts\SubscriptionRepositoryInterface;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function __invoke(Request $request)
    {
        \Illuminate\Support\Facades\Log::info("WEBHOOK", [$request->all()]);

        $data = $request->all();

        // Handle PayPal order payment webhooks
        if (isset($data['event_type']) && $data['event_type'] == 'PAYMENT.CAPTURE.COMPLETED') {
            self::paymentCaptureCompleted($data);
        }

        if (isset($data['event_type']) && $data['event_type'] == 'PAYMENT.SALE.COMPLETED') {
            self::paymentSaleCompleted($data);
        }

        if (isset($data['event_type']) && $data['event_type'] == 'BILLING.SUBSCRIPTION.ACTIVATED') {
            self::billingSubscriptionActivated($data);
        }

        if (isset($data['event_type']) && $data['event_type'] == 'BILLING.SUBSCRIPTION.SUSPENDED') {
            self::billingSubscriptionSuspended($data);
        }

        if (isset($data['event_type']) && $data['event_type'] == 'BILLING.SUBSCRIPTION.PAYMENT.FAILED') {
            self::billingSubscriptionPaymentFailed($data);
        }

        return true;
    }

    public static function paymentCaptureCompleted($data)
    {
        if (isset($data['resource']['id'])) {
            $orderId = $data['resource']['supplementary_data']['related_ids']['order_id'] ?? null;
            
            if ($orderId) {
                try {
                    \Lyre\Billing\Services\Paypal\Payment::capture($orderId);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('PayPal Capture Webhook Error', [
                        'error' => $e->getMessage(),
                        'data' => $data,
                    ]);
                }
            }
        }
    }

    public static function paymentSaleCompleted($data)
    {
        if (isset($data['resource']['billing_agreement_id'])) {
            $subscriptionRepository = app(SubscriptionRepositoryInterface::class);
            $subscriptionRepository->approved($data['resource']['billing_agreement_id'])->resource;

            $invoice = self::retrieveInvoice($data);
            if ($invoice) {
                $invoice->update([
                    'amount_paid' => $data['resource']['amount']['total'],
                    'status' => 'paid',
                ]);
            }
        }
    }

    public static function billingSubscriptionActivated($data)
    {
        if (isset($data['resource']['id'])) {
            $subscriptionRepository = app(SubscriptionRepositoryInterface::class);

            $invoice = self::retrieveInvoice($data);

            if (isset($data['resource']['billing_info']['last_payment']['amount']['value'])) {
                $subscription = $subscriptionRepository->find(['paypal_id' => $data['resource']['id']])->resource;
                $amount = $data['resource']['billing_info']['last_payment']['amount']['value'];

                if ($invoice) {
                    $invoice->update([
                        'amount_paid' => $amount,
                        'status' => $data['resource']['billing_info']['outstanding_balance']['value'] == "0.00" ? 'paid' : 'pending'
                    ]);
                }
            } else if ($data['resource']['billing_info']['outstanding_balance']['value'] == "0.00" && $data['resource']['billing_info']['failed_payments_count'] == 0) {
                // NOTE: Kigathi - June 12 2025 - This is placed here because of subscription plans with trial periods, which do not post a payment.received webhook immediately
                $subscription = $subscriptionRepository->approved($data['resource']['id'])->resource;

                if ($invoice) {
                    $invoice->update([
                        'amount_paid' => 0,
                        'status' => 'paid'
                    ]);
                }
            }

            SendEmails::dispatch(
                email: $subscription->user->email,
                subject: 'Subscription Activated',
                view: 'email.subscriptions.activated',
                data: [
                    'name' => $subscription->user->name,
                    'buttonText' => 'Login',
                    'buttonLink' => config('app.client_url') . '/login'
                ]
            );
        }
    }

    public static function billingSubscriptionSuspended($data)
    {
        if (isset($data['resource']['id'])) {
            $subscriptionRepository = app(SubscriptionRepositoryInterface::class);
            $subscription = $subscriptionRepository->find(['paypal_id' => $data['resource']['id']])->resource;
            $subscription->update(['status' => 'paused']);

            SendEmails::dispatch(
                email: $subscription->user->email,
                subject: 'Subscription Suspended',
                view: 'email.subscriptions.suspended',
                data: [
                    'name' => $subscription->user->name,
                    'buttonText' => 'Renew Now',
                    'buttonLink' => config('services.paypal.base_uri') . "/billing/subscriptions/{$data['resource']['id']}/capture"
                ]
            );
        }
    }

    public static function billingSubscriptionPaymentFailed($data)
    {
        if (isset($data['resource']['id'])) {
            $subscriptionRepository = app(SubscriptionRepositoryInterface::class);
            $subscription = $subscriptionRepository->find(['paypal_id' => $data['resource']['id']])->resource;

            $invoice = self::retrieveInvoice($data);
            if ($invoice) {
                $invoice->update([
                    'amount_paid' => $data['resource']['amount']['total'],
                    'status' => 'paid'
                ]);
            }

            SendEmails::dispatch(
                email: $subscription->user->email,
                subject: 'Subscription Suspended',
                view: 'email.subscriptions.suspended',
                data: [
                    'name' => $subscription->user->name,
                    'buttonText' => 'Renew Now',
                    'buttonLink' => config('services.paypal.base_uri') . "/billing/subscriptions/{$data['resource']['id']}/capture"
                ]
            );
        }
    }

    public static function retrieveInvoice($data)
    {
        if (isset($data['resource']['custom_id']) || isset($data['resource']['custom'])) {
            $invoiceNumber = isset($data['resource']['custom_id']) ? $data['resource']['custom_id'] : $data['resource']['custom'];
            $invoice = Invoice::where('invoice_number', $invoiceNumber)->first();
            return $invoice;
        }

        return null;
    }
}
