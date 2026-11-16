# Stripe Refund Webhook patch notes

This patch adds a separate Stripe Refund lifecycle processor for the generic
Payment Core. It is a source patch candidate, not a published Composer release.

Included changes:

- Route Stripe Refund events to `ProcessStripeRefundWebhook`.
- Correlate Stripe Refund objects to local Core Payments using PaymentIntent
  references and package metadata.
- Synchronize external Dashboard-created Refunds into the local Refund ledger.
- Recalculate `refunded_amount` from completed Refund ledger rows.
- Avoid duplicate local Refund rows across `refund.created`, `refund.updated`,
  and `charge.refunded` deliveries.
- Fetch all pages of refunds for a Charge when Stripe's embedded collection is
  incomplete.
- Prevent an older event or a conflicting terminal event from downgrading a
  terminal Refund state.

Verification performed in the build environment:

- PHP syntax check for all PHP source files.
- Composer manifest JSON parse.
- ZIP archive integrity check.

Not performed in the build environment:

- Live Laravel application boot.
- MySQL migration execution.
- Stripe API / Stripe CLI end-to-end run.

Run the included `docs/stripe-refund-webhooks.md` verification steps in the
consumer Playground before publishing a release tag.
