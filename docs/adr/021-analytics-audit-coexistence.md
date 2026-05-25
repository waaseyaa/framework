# ADR-021 — analytics and audit packages coexist; no rename performed

**Status:** Accepted
**Date:** 2026-05-25
**Mission:** `empty-package-decisions-analytics-billing-aischema-01KSEFV4` (WP01)
**Consumer mission:** `ocap-audit-log-substrate-01KSEFTF`

## Context

WP01 of this mission was originally specified as a `packages/analytics/` → `packages/audit/` rename (`git mv`), on the assumption that the analytics package was an empty placeholder that should be repurposed as the OCAP audit substrate home.

Before WP01 executed, the companion mission `ocap-audit-log-substrate-01KSEFTF` independently created `packages/audit/` as a new package — not a rename of `packages/analytics/`. The audit package was bootstrapped with a distinct composer manifest (`waaseyaa/audit`), a full OCAP-oriented description, and Layer 1 dependencies (`waaseyaa/entity`, `waaseyaa/entity-storage`, `waaseyaa/foundation`). It was registered in Layer 1 of the CLAUDE.md layer table and received a full `AuditServiceProvider`, contract interfaces, entity classes, and storage schema.

Meanwhile `packages/analytics/` was left intact. It contains:

- `src/UmamiClient.php` — Umami pageview proxy (HTTP sender)
- `templates/_umami_script.html.twig` — Twig partial for embedding the Umami JS tracker
- `assets/umami.js` — JS helper
- `composer.json` — `waaseyaa/analytics`, zero `waaseyaa/*` dependencies (Layer 0 eligible)

## Decision

**No rename is performed.** The two packages serve distinct purposes and coexist:

| Package | Composer name | Layer | Purpose |
|---|---|---|---|
| `packages/analytics/` | `waaseyaa/analytics` | 0 | Umami pageview proxy — behavioural analytics integration |
| `packages/audit/` | `waaseyaa/audit` | 1 | OCAP append-only audit log substrate — governance event recording |

`waaseyaa/analytics` is classified as Layer 0 (no `waaseyaa/*` runtime dependencies). `waaseyaa/audit` is Layer 1 (depends on `waaseyaa/entity`, `waaseyaa/entity-storage`, `waaseyaa/foundation`).

This outcome corresponds to **Strategy (ii) — shim split** from the original WP01 plan, achieved organically: the two concerns were separated at package creation time rather than by a post-hoc carving step.

## Consequences

- `waaseyaa/analytics` remains the home for Umami behavioural analytics. Future Umami work (A/B testing, event tracking) belongs here.
- `waaseyaa/audit` is the home for OCAP governance auditing. Access-denied recording, retention policy hooks, and the audit query API belong here.
- The CLAUDE.md Layer Architecture table has been updated: `analytics` appears in Layer 0, `audit` in Layer 1.
- The CLAUDE.md orchestration table has an explicit row for `packages/analytics/*` pointing to `packages/analytics/README.md`.
- No downstream consumer migration is required — `waaseyaa/analytics` retains its package name and namespace.
- The `ocap-audit-log-substrate-01KSEFTF` mission owns all future work on `packages/audit/`.
