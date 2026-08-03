# Lyre Billing

`lyre/billing` adds subscription and billing domain primitives to Lyre applications.

## What it provides
- Models: `Subscription`, `SubscriptionPlan`, `PaymentMethod`, `Invoice`, `Transaction`, `Billable*`
- REST endpoints for subscriptions, plans, payment methods
- Unified payment orchestration via `Lyre\Billing\Services\PaymentManager`
- Gateway adapters under `src/Services/Gateways` for:
  - `mpesa` (real initiation + webhook handling)
  - `paypal` (real initiation + capture/return handling)
  - `stripe` (scaffold with status-safe placeholders)
  - `paystack` (scaffold with status-safe placeholders)
- Additional billing routes:
  - `GET /api/subscriptionplans/{plan}/subscribe`
  - `GET /api/subscriptions/{subscription}/approved`
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
