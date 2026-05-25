# Specification Quality Checklist: Offline-First Sync Substrate

**Feature:** [spec.md](../spec.md)

## Content Quality

- [x] Mission scoped to the SUBSTRATE only (Dexie + Workbox + FSM + ConflictResolver + auth-guard + server-side sync endpoints + status badge). Per-surface integration (Drive, Forms, Docs) explicitly deferred to subsequent missions per alpha-to-beta-plan §2.
- [x] Why-this-mission-exists grounds the work in gap-matrix A7 + alpha-to-beta-plan §1 item #6 + the Robinson-Huron Treaty Nations geographic constraint (intermittent backhaul).
- [x] Framework-substrate scoping decision explicit ("This is FRAMEWORK substrate not Anokii-specific because Russell explicitly listed it under framework work").
- [x] Design document `/tmp/waaseyaa-design-offline-first.md` cited by section anchor throughout: §"Two-Axis Integration" (FR-001 schema), §"Sync Protocol" (FR-006 acknowledge body), §"Conflict policy" (FR-004 / C-003), §"Offline Reads" (FR-009 classification filter), §"Reconnect Flow" (audit-pending replay), §10 (headline recommendation — no Yjs at v1).
- [x] DIR-005 axis preservation treated as primary constraint (C-002) — IndexedDB schema mirrors `(entity_id, langcode, vid)`; no custom client-side revision model.
- [x] Cross-mission integrations explicit: depends on `ocap-audit-log-substrate-01KSEFTF` + `classification-retention-engine-01KSEFTH`; consumed by Drive / Forms / Docs per-surface missions.
- [x] In-scope / out-of-scope enumerated. Out-of-scope items each have a documented future-mission home.
- [x] Risks named with mitigations sourced from design-offline-first.md §9: token cipher source, schema migration, queue runaway, stale SSE, label drift offline, browser-profile leakage.

## Requirement Completeness

- [x] FR-001 through FR-015 each testable via unit, integration, Playwright, or PHP-side phpunit.
- [x] FR-012 integration test concrete (full offline-write-reconnect-conflict-resolve-drain cycle) — single canonical scenario.
- [x] FR-013 Playwright e2e committed (deferred run per lane-worktree limitation).
- [x] NFR-001 DIR-005 — IndexedDB schema mirrors framework substrate.
- [x] NFR-002 DIR-004 — audit substrate integrated via `AuditWriterInterface` + `POST /api/sync/audit-batch`.
- [x] NFR-003 DIR-006 — codified gates green.
- [x] NFR-004 sync engine resilience (no non-recoverable state, exponential backoff, failed lane).
- [x] NFR-005 Workbox cache invalidation (stale-while-revalidate, 24h default).
- [x] C-001 dependency on cluster's first two missions explicit.
- [x] C-002 two-axis substrate preservation.
- [x] C-003 multi-submission-merge default for governed data — NOT LWW.
- [x] C-004 SW is read-cache + write-queue only — does NOT enforce access policy.
- [x] C-005 substrate-only scope — no per-surface integration.
- [x] No `should` / `might` / `consider` hedging.

## Filing Readiness

- [x] `spec.md`, `plan.md`, `tasks.md`, `wps.yaml`, four `tasks/WP*.md` files all populated.
- [x] Four WPs with correct dependency graph (WP02 → WP01; WP03 → WP01; WP04 → WP02 + WP03).
- [x] Charter directives DIR-004, DIR-005, DIR-006, DIR-007 (Nuxt SPA bet) referenced by ID.
- [x] Gap-matrix row A7 + alpha-to-beta-plan §1 item #6 referenced.
- [x] Design-doc anchors cited (§"Two-Axis Integration", §"Conflict policy", §"Offline Reads", §"Reconnect Flow", §10).
- [x] Decision-preference order (preserve OCAP audit lineage > minimise vendor lock-in > don't break codified policy gates) explicit at end of "Decisions deferred".
- [x] Four implementer judgements documented with recommendation (PWA module choice, token cipher mechanism, conflict-policy declaration mechanism, audit timestamp preservation).
- [x] Reviewer focus enumerated in plan.md (8 items covering DIR-005 + DIR-004 + C-003 multi-submission-merge + C-004 SW-no-policy + NFR-004 resilience + cross-layer + Mercure sync.conflict + CLAUDE.md gotchas).
- [x] Cross-mission ordering: MUST merge before per-surface offline integration missions; future Yjs extension is a separate mission.
