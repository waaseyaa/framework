---
work_package_id: WP05
title: Docs + cross-mission surface map (SC-004) + verify
dependencies:
- WP02
- WP03
- WP04
requirement_refs:
- FR-012
- SC-004
- SC-006
- C-006
- C-007
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts generated on main. Implementation may branch from main. Completed changes merge back into main.
subtasks:
- T014
- T015
history: []
authoritative_surface: kitty-specs/ai-agent-bimaaji-tools-01KS5VKR/
execution_mode: planning_artifact
owned_files:
- packages/ai-agent/README.md
- CHANGELOG.md
- kitty-specs/ai-agent-bimaaji-tools-01KS5VKR/verification.md
tags: []
---

## Objective

Final WP. Document the four tools, record the SC-004 cross-mission surface contract for M3, and verify all gates green on the mission's tip.

## Context

The four tools' argument schemas + return envelopes are stable after WP02/WP03. M3 (`bimaaji-mcp-bridge-01KS5VS8`) wraps them as MCP tools and per SC-004 must verify no shape changes between M2 and M3. This WP pins the contract in `verification.md` so M3's first WP has a single canonical reference rather than inferring from source.

## Subtasks

### T014 — Docs

Edit `packages/ai-agent/README.md`:

- Add a "Bimaaji-backed tools" section enumerating the four tools, their capabilities, and a `bin/waaseyaa ai:run` example (FR-012).
- Link to the M2 mission spec and to `kitty-specs/ai-agent-bimaaji-tools-01KS5VKR/verification.md`.

Edit `CHANGELOG.md` `[Unreleased]`:

- One bullet describing the four-tool surface, the two new capability strings, and the explicit no-disk-write invariant for `GeneratePatchTool`.

### T015 — Verification log + SC-004 surface map

Create `kitty-specs/ai-agent-bimaaji-tools-01KS5VKR/verification.md` documenting:

- Local gate sweep (cs-check, phpstan, layer, composer-policy, dead-code, getQuery, full `composer verify` exit code).
- Test surface summary (contract test count, integration test count, total assertions).
- **SC-004 tool-shape contract** — for each of the four tools, record:
  - FQCN
  - `#[AsAgentTool]` parameters (name, capability, destructive)
  - Argument list with types
  - Return envelope schema (`ok`, `data` shape, `meta`, `error` shape)
  - Pointer to the contract test that pins each shape
- Provenance: list of mission PRs in landing order.

This file is the SC-004 anchor — M3's first WP imports it via reference (not via code dep — these are not class strings) and verifies its WP01 tool wrappers match every field.

## Test strategy

No new tests in this WP. Verification is the manual gate-sweep recorded in `verification.md`.

## Definition of Done

- [ ] README has the "Bimaaji-backed tools" section with the four-tool enumeration and a `bin/waaseyaa ai:run` example.
- [ ] CHANGELOG `[Unreleased]` has one bullet for this mission.
- [ ] `verification.md` exists with all four tool-shape entries, gate exit codes, and PR provenance.
- [ ] Local gates all exit 0.
- [ ] No `--no-verify` used on any commit in this mission branch (C-007).

## Risks and notes

- **CHANGELOG duplication:** If the release-cut sweeps `[Unreleased]` before this PR merges, the bullet ends up under the new version heading. That's fine — track via release notes after merge (per memory `feedback_pr_traceability_signals`).
- **SC-004 enforcement:** This WP pins the contract but doesn't enforce it — enforcement is M3's WP01 responsibility. The verification.md surface map is the single source of truth.
