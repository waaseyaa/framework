# Specification Quality Checklist: Mercure Broadcast Monitor (M5D)

**Feature**: [spec.md](../spec.md)

## Content Quality
- [x] M5D scope (broadcast monitor: channels + events SSE + subscribers) crisply bounded against M5A (AI aggregations), M5B (AI runs/replay), M5C (MCP admin) — no owned_files overlap
- [x] Cross-layer constraint (foundation L0 → api L4) is called out; even though L0→L4 is downward, the CodifiedContext discipline keeps the read contract in api so adapter swaps (Redis, Mercure-as-a-service) are one-file changes
- [x] Read-only mission — no publish/write surface; the existing `BroadcastStorage::push()` is unchanged
- [x] Single-host limitation (C-005) called out and documented in `docs/specs/broadcasting.md`

## Requirement Completeness
- [x] FR/NFR/C separated, IDs unique (FR-001..FR-010, NFR-001..NFR-004, C-001..C-005)
- [x] Acceptance criteria present and gate-mapped (phpunit + cs + phpstan + 4 codified gates + admin npm test/typecheck/lint)
- [x] Edge cases: empty `_broadcast_log` (empty array, no 500), malformed `data` JSON (empty `[]`, no fatal), missing/malformed subscribers.json (empty array), null stream dep (SSE `disabled` frame + close), concurrent subscribers.json writes (atomic temp-then-rename), limit > 1000 (clamp to 1000)
- [x] FR-008 names the dead-code-in-production guard explicitly (kernel-boot test must fail without ANY of the three SP bindings — three independent checks)
- [x] NFR-004 names the SSE keepalive invariant (15s) — without it production proxies buffer
- [x] C-004 names the identity-leak invariant (no Auth / Cookie / UA / 64-char hex in subscriber rows) with reviewer-grep guard
- [x] No cross-mission dependencies — broadcasting is L0 and stands alone

## Filing Readiness
- [x] Two WPs in `wps.yaml` with correct dependency declaration (WP02 → WP01)
- [x] Tracks audit row C-L0-04 under umbrella issue #1415
- [x] Pattern reference (M5A) and CodifiedContext spec referenced inline
- [x] Implementer preference order codified: preserve OCAP audit lineage > minimise vendor lock-in > don't break codified policy gates
