# Specification Quality Checklist: OCAP Audit Log Substrate

**Feature:** [spec.md](../spec.md)

## Content Quality

- [x] Mission scoped to the substrate layer only (event table + write contract + query contract + listeners + CLI prune + JSON:API endpoint). Admin SPA explorer page explicitly deferred to M-A5 flagship.
- [x] Why-this-mission-exists grounds the work in DIR-004 + gap-matrix row A3 + alpha-to-beta-plan §1 item #2. No invented motivation.
- [x] Cross-layer constraint (audit L0 + api L4 adapter pattern via CodifiedContext) called out explicitly; the L0↔L4 adapter wiring approach is documented (with the implementer-decision note about adapter file placement).
- [x] Package decision (`analytics` rename → `audit`) made explicitly with rationale and coordination note for the `empty-package-decisions-*` mission.
- [x] In-scope / out-of-scope enumerated. Three downstream missions (classification-retention, versioned-blob-media, offline-first-sync) named explicitly with merge-ordering constraints.
- [x] Risks named with mitigations: bottleneck (best-effort + indices + perf budget), schema lock-in (rename gate), listener event-name drift (contract test), OCAP scope creep (C-005 hard line).

## Requirement Completeness

- [x] FR-001 through FR-015 each testable via grep, unit test, integration test, or CLI smoke.
- [x] NFR-001 (no audit-write 500s) has a concrete chaos test (`AuditChaosTest`).
- [x] NFR-002 (cross-layer cleanliness) has concrete greps in Acceptance.
- [x] NFR-003 (no new unbound `getQuery()` chains) tied to `bin/check-getquery-bindings`.
- [x] NFR-004 (`@api` PHPDoc) tied to `bin/check-dead-code` gate enforcement.
- [x] FR-013 dead-code guard is explicitly called out as the "remove the binding, test must fail" mechanism — reviewer instruction in plan.md.
- [x] C-001 (append-only single table), C-002 (cross-layer cleanliness), C-003 (best-effort), C-004 (rename coordination), C-005 (retention scope split) all unambiguous MUST / MUST NOT.
- [x] No `should` / `might` / `consider` hedging — all requirements MUST / MUST NOT.

## Filing Readiness

- [x] `spec.md`, `plan.md`, `tasks.md`, `wps.yaml`, three `tasks/WP*.md` files all populated.
- [x] Three WPs (WP01 substrate; WP02 listeners; WP03 endpoint + CLI). WP02 + WP03 parallel after WP01.
- [x] Charter directives DIR-004, DIR-005, DIR-006 referenced by ID.
- [x] Gap-matrix row A3 + alpha-to-beta-plan §1 item #2 referenced by location.
- [x] Decision-preference order (preserve OCAP audit lineage > minimise vendor lock-in > don't break codified policy gates) explicit at end of "Decisions deferred to implementer".
- [x] Implementer can act on the spec without coming back for questions: every entity field, every interface signature, every test seed count is specified. The one implementer judgement call (`ApiAuditQueryAdapter` file placement) is documented with a recommended approach.
- [x] Reviewer focus enumerated in plan.md §Reviewer focus (8 items covering layer cleanliness, two-axis preservation, best-effort proof, append-only enforcement, self-audit on prune, dead-code guard, rename completeness, getQuery hygiene).
