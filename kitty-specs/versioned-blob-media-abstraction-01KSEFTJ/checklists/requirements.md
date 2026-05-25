# Specification Quality Checklist: Versioned-Blob Media Abstraction

**Feature:** [spec.md](../spec.md)

## Content Quality

- [x] Mission scoped to one substrate concern (blob revisioning + CAS dedup); binary streaming endpoint explicitly deferred; per-version classification override deferred; GC deferred; translatable blob content deferred.
- [x] Why-this-mission-exists grounds the work in gap-matrix A1 + alpha-to-beta-plan §1 item #5 + the two-axis substrate's existing revisionable-axis pattern.
- [x] DIR-005 axis preservation is treated as primary constraint (C-003) — this mission EXTENDS the substrate at the blob layer; it does NOT alter the entity-storage two-axis substrate.
- [x] Cross-mission integrations called out: OCAP audit substrate (audit-event-kind amendment — Risks + C-004), classification mission (parent-resolver for label inheritance — FR-011 + risk), offline-first-sync substrate (downstream consumer of version metadata).
- [x] In-scope / out-of-scope enumerated. Out-of-scope items each have a documented future-mission home.
- [x] Risks named with mitigations: audit-enum coordination, CAS dedup race, blob deletion orphans, MIME/size spoofing, classification inheritance brittleness.

## Requirement Completeness

- [x] FR-001 through FR-015 each testable via unit, integration, or CLI smoke.
- [x] FR-013 dedup-correctness integration test is concrete (4-upload scenario with 1 dedup hit).
- [x] FR-014 forbidden-version test concrete (admin sees all, restricted sees filtered).
- [x] NFR-001 DIR-005 explicit — entity-storage two-axis NOT altered.
- [x] NFR-002 DIR-004 — every blob write fires audit event, every read fires audit event.
- [x] NFR-003 DIR-006 — codified gates green.
- [x] NFR-004 cross-layer cleanliness — adapter pattern preserves L2 → L4 independence.
- [x] NFR-005 perf budget — < 5ms dedup-hit overhead.
- [x] C-001 metadata-only saves DON'T bump version (per-save semantics explicit).
- [x] C-002 canonical URI scheme `cas://sha256/{first2}/{rest}`.
- [x] C-003 two-axis preservation.
- [x] C-004 audit-enum amendment is the ONLY change to packages/audit — additive only.
- [x] C-005 no delete API at v1 (append-only by convention).
- [x] No `should` / `might` / `consider` hedging.

## Filing Readiness

- [x] `spec.md`, `plan.md`, `tasks.md`, `wps.yaml`, four `tasks/WP*.md` files all populated.
- [x] Four WPs with proper sequential dependency graph.
- [x] Charter directives DIR-004, DIR-005, DIR-006 referenced by ID.
- [x] Gap-matrix row A1 + alpha-to-beta-plan §1 item #5 referenced.
- [x] Decision-preference order (preserve OCAP audit lineage > minimise vendor lock-in > don't break codified policy gates) explicit at end of "Decisions deferred".
- [x] Three implementer judgements documented with recommended approach (CAS URI derivation, binary-stream endpoint, parent-resolver timing).
- [x] Reviewer focus enumerated in plan.md (8 items covering DIR-005 + CAS dedup + per-version access + audit-enum amendment + cross-layer + MIME re-derivation + cascade-delete + classification inheritance).
- [x] Cross-mission ordering: SHOULD merge before offline-first-sync; MAY parallel per-record AI flagship.
