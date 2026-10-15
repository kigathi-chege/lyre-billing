# Lyre Billing

`lyre/billing` adds subscription and billing domain primitives to Lyre applications.

## What it provides
- Models: `Subscription`, `SubscriptionPlan`, `PaymentMethod`, `Invoice`, `Transaction`, `Billable*`
- REST endpoints for subscriptions, plans, payment methods
- Unified payment orchestration via `Lyre\Billing\Services\PaymentManager`
- Gateway adapters under `src/Services/Gateways` for:
  - `mpesa` (real initiation + webhook handling)
  - `paypal` (real initiation + capture/return handling)
  - `stripe` (recurring checkout, verified webhook handling and reconciliation)
  - `paystack` (scaffold with status-safe placeholders)
- Additional billing routes:
  - `GET /api/subscriptionplans/{plan}/subscribe`
  - `PATCH|PUT /api/subscriptions/{subscription}/revoke-renewal`
  - `PATCH|PUT /api/subscriptions/{subscription}/restore-renewal`
  - `POST /api/billing/webhook`
- Filament resources via `LyreBillingFilamentPlugin`

## Required Stripe webhook events
The Stripe webhook endpoint (`POST /api/billing/webhook`) must have these events **enabled in the Stripe dashboard** — event selection is not managed by code. Missing events silently degrade lifecycle handling:

- `checkout.session.completed` — activate a new subscription after checkout.
- `invoice.paid` / `invoice.payment_succeeded` — record each payment (initial + renewals) and advance `end_date` from the invoice period end.
- `invoice.payment_failed` — dunning / payment-failed notice.
- `customer.subscription.created` / `customer.subscription.updated` — sync status + coverage `end_date` (plan changes, cancellations scheduled at period end).
- `customer.subscription.deleted` — mark cancelled.
- `customer.subscription.resumed` — resume after pause.

Note: since `invoice.paid` now advances `end_date` itself (forward-only), coverage no longer depends on `customer.subscription.updated` being delivered — but `.updated`/`.deleted` remain required for cancellations and plan changes.

## Renewal management

- The authenticated subscription owner can disable or restore renewal. The configured
  Lyre super-admin role can perform the same action for any user's subscription.
- Stripe-backed subscriptions set `cancel_at_period_end` at Stripe before the local
  `auto_renew` value is changed. `customer.subscription.updated` and Stripe
  reconciliation also repair the local value from Stripe.
- Subscriptions without a provider reference retain local-only renewal state for
  manual/legacy billing workflows.
- PayPal does not expose reversible end-of-current-period cancellation. Renewal
  changes for a PayPal-backed subscription therefore return HTTP 422 rather than
  recording a local state that disagrees with PayPal.

## Install
```bash
composer require lyre/billing
```

Publish migrations:
```bash
php artisan vendor:publish --provider="Lyre\Billing\Providers\LyreBillingServiceProvider"
php artisan migrate
```

Seed default payment methods (optional):
```bash
php artisan db:seed --class="Lyre\\Billing\\Database\\Seeders\\PaymentMethodSeeder"
```

## Filament
Register plugin in your panel provider:

```php
use Lyre\Billing\Filament\Plugins\LyreBillingFilamentPlugin;

$panel->plugins([
    LyreBillingFilamentPlugin::make(),
]);
```

## Notes
- Billing package follows core Lyre controller/repository/resource conventions.
- Keep webhook route behavior stable when integrating external payment providers.
- Checkout surfaces in consuming apps should call `PaymentManager` instead of using provider clients directly.
- Provider-specific payloads and callback interpretation belong in gateway adapters, not in app controllers.


## 2.7: observable Stripe billing

The opt-in `billing.observability.enabled` path shares authoritative provider normalization between webhook processing and reconciliation. It records verified event envelopes, bounded processing attempts, provider invoice/payment identities, reconciliation runs and before/after state changes. New evidence models follow `Lyre\Model` and its configured table-prefix/activity infrastructure.

Install the additive migrations before enabling `BILLING_OBSERVABILITY_ENABLED=true`. Use an asynchronous queue, shared atomic cache locks, worker timeout of at least 90 seconds and queue retry visibility greater than that timeout. Schedule `billing:dispatch-events` every minute to recover receipts whose original queue dispatch was lost or whose processing lease expired. The consuming application owns worker supervision, reconciliation scheduling, admin authorization, notifications and dashboard presentation.

Coverage is calculated from settled invoice service periods, explicit trials and configured grace; an unpaid future billing period is not paid access. Current catalog prices do not rewrite historical provider contracts. Each provider billing cycle/payment has a stable identity; legacy checkout/subscription lifecycle telemetry is preserved as superseded rather than counted as another completed payment. Partial refunds and disputes retain an explicit manual-review outcome.

Signed raw envelopes and provider snapshots are encrypted with Laravel's application key. Preserve that key across deployments and backups. The event ledger is immutable evidence; reconciliation reconstructs provider facts without inventing historical webhook events. Payment-history verification is bounded and reports deferred work, which advances on later runs.

State-change evidence includes notification delivery and email outbox fields. Consumers should use deterministic database notification identities, bounded mail retries and recorded delivery outcomes. Transport acceptance is not proof of recipient delivery; SMTP retries after an ambiguous crash can duplicate a message even with a stable message ID.

The optional `BILLING_TEST_EXPIRY_ENABLED` helper only applies on allowed non-production environments to verified Stripe test-mode subscriptions. It caps simulated local coverage while preserving provider paid-through evidence. It does not advance Stripe billing time or change a real payment contract.

Configure invoice/payment failure and action-required events, subscription trial/update notices, checkout expiration, refund and dispute events in addition to the normal checkout/subscription/invoice events above. Unsupported events receive an explicit ignored outcome. Notification-only supported events have durable, deduplicated state-change notices even when financial facts are unchanged.

The feature flag defaults off for existing consumers. Existing public routes remain unchanged. The Aspire consumer integration tests cover signature/deduplication, modern provider reads, monthly invoice separation, projection repair, bounded recovery/history verification, refund review, access/grace and UAT expiry safety. Validate consuming application's existing billing paths before enabling the new processor.

Early verified webhook events can wait for checkout to persist its subscription reference. These attempts are recorded as `deferred` with `awaiting_checkout_subscription_reference`, retried within the existing five-attempt budget, and escalated for review only if the dependency remains unresolved.
