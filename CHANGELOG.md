# Changelog

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
