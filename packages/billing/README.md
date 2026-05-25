# waaseyaa/billing

**Layer 3 — Services**

Stripe billing for Waaseyaa: subscriptions, checkout, customer portal, plan tiers.

`BillingManager` is the single entry point for plan tier resolution, checkout session creation, and customer-portal URLs. `StripeClientInterface` plus `FakeStripeClient` make the integration testable without hitting Stripe; `WebhookHandler` dispatches Stripe webhook events into framework `DomainEvent`s.

Key classes: `BillingManager`, `BillingServiceProvider`, `CheckoutSession`, `PlanTier`, `StripeClientInterface`, `WebhookHandler`.

## Scope

This package is **v0.1 scaffolding**. The public surface (`BillingManager`, `StripeClientInterface`, `WebhookHandler`, `PlanTier`, `CheckoutSession`, `SubscriptionData`, `FakeStripeClient`, `BillingServiceProvider`) is marked `@api` to preserve it from dead-code removal while the integration remains partially wired.

## Out of scope for v0.1

Full Stripe billing integration — including webhook signature verification, subscription lifecycle management, and payment failure handling — is **deferred to post-v0.1**. The scaffold exists to:

1. Reserve the `waaseyaa/billing` package namespace.
2. Define the `StripeClientInterface` contract so downstream code can type-hint against it.
3. Provide `FakeStripeClient` for testing billing-adjacent logic without hitting Stripe.

## Roadmap

Billing activation is planned for the v0.2 cycle alongside the Minoo distribution layer. See the `empty-package-decisions-analytics-billing-aischema-01KSEFV4` mission (WP02) and the alpha-to-beta plan for deferral rationale.
