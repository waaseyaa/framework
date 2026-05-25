# Specification Quality Checklist: Per-Record AI Access Flagship (M-A5)

**Mission:** `per-record-ai-access-flagship-01KSEFT5`
**Reviewed against:** M5A `ai-observability-dashboard-01KSE9BX` (shape) + `charter-amendment-anokii-track-01KSEFE0` (governance density bar).

## Content Quality

- [x] M-A5 scope is bounded against M-A3 (audit table — out of scope) and M-A4 (classification engine — out of scope); `'inherit'` AI-access resolution explicitly defers to M-A4 with a documented `'yes'` fallback until then.
- [x] Cross-layer interaction is called out: L1 (`access`, `field`) + L5 (`ai-tools`, `ai-agent`) + L6 (`mcp`, `admin`); no upward imports introduced (C-002 codifies the constraint).
- [x] CodifiedContext three-tier pattern referenced as the model for any cross-layer adapter binding (`docs/specs/codified-context-integration.md`).
- [x] DIR-004 (OCAP-by-architecture) and DIR-006 (codified gates) referenced; mission is explicitly framed as the operational embodiment of DIR-004.
- [x] DIR-003 (Greenfield Removal Policy) cited as licence for the `AgentToolInterface::execute()` signature break.
- [x] Three parallel WPs declared with no inbound dependency edges; per-WP dead-code-in-production guard tests (FR-004, FR-007, FR-011) collectively prove the wiring is real per-WP, not just in aggregate.
- [x] Dead-code-in-production risk (R1) is the primary risk; mitigation is codified per-WP and called out in reviewer focus item (a).
- [x] No timeline language ("by month X", "in Q2", "after N weeks") in spec.md, plan.md, tasks.md, or any WP file.

## Requirement Completeness

- [x] 13 FRs covering: tool boundary signature (FR-001), per-record gating (FR-002), governed-data marker (FR-003), WP01 guard (FR-004), MCP serializer wiring (FR-005), redaction-shape contract (FR-006), WP02 guard (FR-007), field type (FR-008), entity registration + migrations (FR-009), policy (FR-010), WP03 guard (FR-011), admin UI (FR-012), spec stamps + CHANGELOG (FR-013).
- [x] 4 NFRs covering: layer integrity (NFR-001), codified gates (NFR-002), audit lineage (NFR-003), parallel-merge property (NFR-004).
- [x] 4 Constraints covering: no BC shim (C-001), no L1 → L5 imports (C-002), redaction-shape exclusivity (C-003), `'inherit'` default + fallback semantics (C-004).
- [x] Every requirement has a testable acceptance hook (integration test, unit test, grep target, or `bin/check-*` gate).
- [x] Every WP's owned_files list in wps.yaml matches the tasks.md `Owned Files` list verbatim.
- [x] Every WP's subtask IDs in wps.yaml are referenced by phased sections in the matching WP file (T001..T004 in WP01, T005..T007 in WP02, T008..T011 in WP03 — IDs unique across the mission).

## Filing Readiness

- [x] Mission scaffold dir populated: `spec.md`, `plan.md`, `tasks.md`, `wps.yaml`, `tasks/WP01-tool-boundary-access-checker.md`, `tasks/WP02-mcp-field-access-parity.md`, `tasks/WP03-per-file-ai-toggle.md`, `checklists/requirements.md`.
- [x] Each WP file carries YAML frontmatter matching the `tasks/README.md` template.
- [x] Commit-footer convention codified: `Refs gap-matrix-A5` on every commit; no new GitHub issue required.
- [x] Spec updates enumerated (`docs/specs/access-control.md`, `docs/specs/field-access.md`, `docs/specs/ai-integration.md`, `docs/specs/mcp-endpoint.md`) and bound to the WPs that own them.
- [x] CHANGELOG `[Unreleased]` updates enumerated per WP with the exact text shape.
- [x] Decisions deferred to implementer (D-D1..D-D4) each carry guidance + preference order (preserve OCAP audit lineage > minimise vendor lock-in > don't break codified policy gates).
- [x] Reviewer focus list in plan.md enumerates the six verification points reviewers MUST independently confirm (a) FR-004/FR-007/FR-011 guard tests fail without the wiring; (b) D-D3 marker enumeration; (c) C-002 layer integrity; (d) C-003 redaction-shape uniqueness; (e) NFR-003 audit lineage; (f) `getQuery()` baseline discipline.
