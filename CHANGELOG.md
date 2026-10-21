# Changelog

## 2.8.0

- Plans are composed from billables (bundles), billable items (content by type-map token and id, "any" content with picks, or meters) and subscription_plan_billables (per-plan limit, overage price, metadata).
- PlanComposition (with legacy entitlements_config adaptation), access snapshots on subscriptions with captureAccessSnapshot(force), and generated PlanBenefits; plans expose `benefits` and `selectable`.
- Checkout accepts selection ids or slugs, validates picks before any provider session, treats missing quotas as zero, requires picks for "any" items and replaces (never stacks) picks on reused pending subscriptions.
- AllowanceService: per-period and per-resource limits on billable_usages, idempotent by key, upsell by default or overage through an OverageBiller (Stripe invoice items).
- Validation rules for billables, items and plan links; meter items for usage billables; slug fillable on usages; seeders autoloadable.
- Filament: bundle item editor by content type/record/meter, plan-link allowance settings, plan catalog tier/highlights/benefits preview, subscription grants column and re-sync action; currency field only when the column exists.

## 2.7.1

- Defer early subscription/invoice events while checkout has not yet persisted its provider subscription reference, with bounded attempts and an explicit waiting reason.
- Restrict customer fallback for subscription events to pending checkouts and reject mismatched provider subscription references.

## 2.7.0

- Add opt-in durable Stripe webhook intake, processing leases, bounded retries and explicit outcomes.
- Share authoritative invoice/payment/access reconciliation across background processing and commands.
- Preserve separate monthly invoices and exact payment amounts; repair local projections and supersede lifecycle telemetry without deleting evidence.
- Support modern provider references, periods and payment pagination, refunds/disputes and bounded history verification.
- Add additive encrypted evidence, reconciliation/state-change records and notification/email delivery fields.
- Keep provider billing periods, settled access coverage, trial/grace and test expiry separate.
- Add guarded non-production test-expiry support and durable notification-only notices.
- Keep existing routes and feature-disabled behavior; use Lyre model/table-prefix/activity conventions for new records.
- Infer Composer versions from immutable release tags and exclude checked-in vendor/lock files from package distributions.
