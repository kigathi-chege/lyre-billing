# `lyre/billing` Agent Guide

## Package Purpose
`lyre/billing` provides billing/subscription entities and API flows (plans, subscriptions, invoices, transactions, payment methods, webhook handling).

## What Belongs In This Package
- Billing domain models and repositories.
- Billing API controllers/routes and billing-specific actions (`subscribe`, `approved`, webhook).
- Billing Filament resources and plugin.
- Payment-provider integrations under `src/Services/*`.

## What Does Not Belong Here
- Generic repository/controller behavior (belongs to core `lyre`).
- Non-billing commerce checkout/cart logic.

## Public API / Stable Contracts
- Route contract in `src/routes/api.php` for subscriptions/plans/payment methods and webhook endpoint.
- Billing model/repository/resource interfaces used by consuming apps.
- Payment orchestration contract through `Lyre\Billing\Services\PaymentManager` and `Contracts\PaymentGatewayInterface`.

## Internal Areas That May Change
- Provider-specific service implementation details preserving endpoint and model contracts.

## Usage Rules
- Use this package for recurring billing/subscription capabilities.
- Keep webhook endpoint behavior stable for external payment provider callbacks.

## Extension Rules
- New gateway/provider support should be isolated under provider-specific service modules.
- Do not change existing endpoint names/payload shapes without coordinated migration.
- Audit namespace consistency before refactors (some files may still assume app namespace usage).

## Testing Requirements
- Validate subscription lifecycle routes and webhook handling paths.
- Validate transaction/invoice CRUD behavior and policy checks.

## Docs To Update When This Package Changes
- Root [AGENTS.md](/Users/chegekigathi/Projects/packages/lyre-packages/AGENTS.md)
- [docs/package-responsibilities.md](/Users/chegekigathi/Projects/packages/lyre-packages/docs/package-responsibilities.md)
- `packages/billing/README.md`
