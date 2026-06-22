# Specification Quality Checklist: MCP Endpoint Admin (M5C)

**Feature**: [spec.md](../spec.md)

## Content Quality
- [x] M5C scope (read-only registry + server config) crisply bounded against M5A (AI aggregations), M5B (AI runs/replay), and M5D (broadcast monitor) — no owned_files overlap
- [x] Cross-layer constraint (mcp L6 → api L4) is called out and the CodifiedContext three-tier pattern is mandated (same as M5A)
- [x] Read-only mission — no mutation surface, so capability editing UI is explicitly deferred to a future M5E
- [x] Per-record AI access policy delegation is required (no controller-side field access logic) per DIR-004 and DIR-006

## Requirement Completeness
- [x] FR/NFR/C separated, IDs unique (FR-001..FR-010, NFR-001..NFR-003, C-001..C-004)
- [x] Acceptance criteria present and gate-mapped (phpunit + cs + phpstan + 4 codified gates + admin npm test/typecheck/lint)
- [x] Edge cases: registry / server-config absent (empty shape), tool names with `.` (URL-encoded once), plaintext token leak (forbidden + dedicated test), `recentInvocations` field redaction (delegated to `EntityAccessHandler`)
- [x] FR-008 names the dead-code-in-production guard explicitly (kernel-boot test must fail without EITHER of the two SP bindings — two independent checks)
- [x] NFR-003 names the plaintext-token leak invariant explicitly with a dedicated unit test
- [x] Cross-mission dependency declared (`blocked_by: per-record-ai-access-flagship-01KSEFT5/WP02`) and wired in `wps.yaml`

## Filing Readiness
- [x] Two WPs in `wps.yaml` with correct dependency declaration (WP02 → WP01)
- [x] Tracks audit row C-L6-01 under umbrella issue #1415
- [x] Pattern reference (M5A) and CodifiedContext spec referenced inline
- [x] Implementer preference order codified: preserve OCAP audit lineage > minimise vendor lock-in > don't break codified policy gates
