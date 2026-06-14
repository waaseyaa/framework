# Specification Quality Checklist: Classification + Retention Engine

**Feature:** [spec.md](../spec.md)

## Content Quality

- [x] Mission scoped to the substrate layer only — field type, label catalogue entity, inheritance resolver, retention-policy entity, three scheduled jobs, admin editor. AI-aware classification deferred to `per-record-ai-access-flagship-*`.
- [x] Why-this-mission-exists grounds the work in gap-matrix A4 + alpha-to-beta-plan §1 item #4 + DIR-004 OCAP doctrine.
- [x] Cross-layer constraint (Layer 1 `packages/field` extension; no upward dependencies) called out explicitly (C-002).
- [x] Dependency on `ocap-audit-log-substrate-01KSEFTF` explicit at top-level (C-001) and at every audit-event dispatch point (FR-007, FR-010, FR-011, FR-012).
- [x] In-scope / out-of-scope enumerated with reasoned splits (translation pipeline deferred, AI integration deferred, eager cascade deferred, event-based triggers deferred).
- [x] Two policy-entity split (`AuditRetentionPolicy` in `packages/audit` vs `RetentionPolicy` in `packages/field`) explained with rationale.
- [x] Risks named with mitigations: cascade explosion (C-003 + next-write resolution), clearance drift (config-driven + audit-logged), hold-vs-purge misconfig (FR-012 daily scan + FR-013 hold-override), perf (persisted columns as cache), PII discovery (explicit `pii: true` marker).

## Requirement Completeness

- [x] FR-001 through FR-016 each testable via unit test, integration test, or CLI smoke.
- [x] FR-013 hold-override has a concrete integration-test assertion (admin without bypass → blocked).
- [x] NFR-001 (DIR-005 two-axis preservation) called out explicitly — classification columns on non-translatable axis.
- [x] NFR-002 (DIR-004 audit logging) tied to FR-007 / FR-010 / FR-011 / FR-012.
- [x] NFR-003 (DIR-006 gates) tied to specific gate scripts.
- [x] NFR-004 (jobs best-effort) tied to BestEffortTest.
- [x] NFR-005 (idempotent label import) called out and gated by config-import re-run.
- [x] C-001 cross-mission dependency, C-002 layer-cleanliness, C-003 cascade scope, C-004 hold-override absolute, C-005 two-axis preservation — all unambiguous MUST / MUST NOT.
- [x] No `should` / `might` / `consider` hedging.

## Filing Readiness

- [x] `spec.md`, `plan.md`, `tasks.md`, `wps.yaml`, four `tasks/WP*.md` files all populated.
- [x] Four WPs with proper dependency graph (WP02 → WP01; WP03 → WP01 + WP02; WP04 → WP02).
- [x] Charter directives DIR-004, DIR-005, DIR-006 referenced by ID.
- [x] Gap-matrix row A4 + alpha-to-beta-plan §1 item #4 referenced.
- [x] Decision-preference order (preserve OCAP audit lineage > minimise vendor lock-in > don't break codified policy gates) explicit at end of "Decisions deferred".
- [x] Implementer can act on the spec without coming back for questions: every field name, every interface signature, every job cadence, every test scenario specified. The five implementer judgements (parent-resolver registration mechanism, PII-marker extension, policy registration wildcard, legal-hold-bypass shipping) are each documented with a recommended approach.
- [x] Reviewer focus enumerated in plan.md (8 items covering DIR-005 + DIR-004 + C-004 hold + NFR-004 best-effort + cross-mission seam + idempotence + layer cleanliness + PII-marker backwards-compat).
- [x] Cross-mission ordering: this mission MUST merge before per-record AI flagship + offline-first-sync; SHOULD merge before versioned-blob-media.
