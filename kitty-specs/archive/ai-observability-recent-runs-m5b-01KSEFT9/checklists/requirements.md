# Specification Quality Checklist: AI Observability — Recent Runs (M5B)

**Feature**: [spec.md](../spec.md)

## Content Quality
- [x] M5B scope (runs list / detail / replay) crisply bounded against M5A (aggregations only) — no owned_files overlap with M5A's WP01/WP02
- [x] Cross-layer constraint (ai-observability L5 → api L4) is called out and the CodifiedContext three-tier pattern is mandated (same as M5A)
- [x] Replay surface (the only non-read action) routes through `_gate: 'ai.trace.replay'` per DIR-004 and DIR-006 — no controller-side access logic
- [x] Span-tree recursion bounded at 32 (FR-003) — payload size cannot blow up on pathological traces

## Requirement Completeness
- [x] FR/NFR/C separated, IDs unique (FR-001..FR-010, NFR-001..NFR-003, C-001..C-004)
- [x] Acceptance criteria present and gate-mapped (phpunit + cs + phpstan + 4 codified gates + admin npm test/typecheck/lint)
- [x] Edge cases: read models absent/disabled (empty shape), malformed span attributes (skip), running traces (no `endedAt`/`durationMs`), pagination clamps (perPage 1..100, page ≥1), span recursion depth (truncate at 32)
- [x] FR-008 names the dead-code-in-production guard explicitly (kernel-boot test must fail without ANY of the three SP bindings — three independent checks, not one)
- [x] Cross-mission dependency declared (`blocked_by: per-record-ai-access-flagship-01KSEFT5/WP01`) and wired in `wps.yaml`

## Filing Readiness
- [x] Two WPs in `wps.yaml` with correct dependency declaration (WP02 → WP01)
- [x] Tracks audit row C-L5-02 under umbrella issue #1415
- [x] Pattern reference (M5A) and CodifiedContext spec referenced inline
- [x] Implementer preference order codified: preserve OCAP audit lineage > minimise vendor lock-in > don't break codified policy gates
