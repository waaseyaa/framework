---
work_package_id: WP02
title: CLAUDE.md surfacing + extraction-log reclassification entry
dependencies:
- WP01
requirement_refs:
- FR-003
- FR-004
- FR-005
- FR-006
- FR-008
- NFR-002
- NFR-003
- C-001
- C-003
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
subtasks: []
history: []
authoritative_surface: CLAUDE.md
execution_mode: code_change
owned_files:
- CLAUDE.md
- docs/specs/extraction-log.md
tags: []
agent: "claude"
shell_pid: "141541"
---
# Work Package Prompt: WP02 — CLAUDE.md surfacing + extraction-log entry

**Mission:** `genealogy-package-extraction-01KSEFTZ`
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)
**Depends on:** WP01 (must be merged into lane).

## CRITICAL — work in the lane worktree

`documentation` execution mode. Touch only `CLAUDE.md` and `docs/specs/extraction-log.md`. The plan's "Distribution Extensions" block, the orchestration-table annotation, and the extraction-log entry are reproduced byte-for-byte in `../plan.md` — copy from there, do not improvise.

## What you are doing

Three surgical inserts:

1. Strip `genealogy` from the Layer 6 table in `CLAUDE.md`.
2. Insert a new `## Distribution Extensions` H2 in `CLAUDE.md` between Layer Architecture and Operation Checklists.
3. Annotate the orchestration-table row for `packages/genealogy/*`.
4. Insert a new `## 2026-05 — genealogy distribution-extension reclassification` H2 at the top of `docs/specs/extraction-log.md`.

## THE pattern to mirror (read first)

- `docs/specs/extraction-log.md` — existing entries (`2026-04 — waaseyaa/groups package extraction`, `2026-04 — Mail API consolidation`) — for shape, vocabulary, follow-ups cadence.
- `CLAUDE.md` `## Layer Architecture` table — to confirm the exact row cell to edit.
- `CLAUDE.md` orchestration table — to find the existing `packages/genealogy/*` row.

## Subtasks

### T006 — Layer 6 table edit (FR-004)

In `CLAUDE.md`, find the Layer 6 row in the `## Layer Architecture` table. Current cell:

```
cli, admin-surface, graphql, mcp, ssr, genealogy, telescope, deployer, inertia, debug
```

Replace with:

```
cli, admin-surface, graphql, mcp, ssr, telescope, deployer, inertia, debug
```

Preserve the surrounding `| 6 | Interfaces |` markdown table structure and pipes.

### T007 — Insert Distribution Extensions H2 (FR-004)

Insert immediately after the Layer Architecture section's last paragraph (after the "Auth and OIDC HTTP routes" exemption paragraph, before `## Operation Checklists`). Copy the block verbatim from `../plan.md` §WP02 "CLAUDE.md — new Distribution Extensions section".

### T008 — Orchestration-table row annotation (FR-005)

Find the row:

```
| `packages/genealogy/*` | — | `docs/specs/genealogy.md`, `docs/specs/relationship-modeling.md` |
```

Replace with:

```
| `packages/genealogy/*` | — (distribution-extension) | `docs/specs/genealogy.md`, `docs/specs/relationship-modeling.md` |
```

### T009 — Extraction-log entry (FR-003, FR-008, C-003)

Open `docs/specs/extraction-log.md`. Insert the `## 2026-05 — genealogy distribution-extension reclassification` block from `../plan.md` §WP02 verbatim. Position: **immediately after** the file's existing intro prose (after the line `See \`waaseyaa:framework-extraction\` skill for the extraction process.`), **before** the first existing `##` heading. This puts the newest entry first (matches existing chronological-descending cadence).

### T010 — split.yml verification (FR-006)

Run:

```bash
grep -n "packages/genealogy" .github/workflows/split.yml
```

Expected output (exactly one line):

```
78:          - { local: 'packages/genealogy', remote: 'genealogy' }
```

If the entry is missing, BLOCK and file an out-of-band note (re-adding the split entry is itself a separate decision that must not be silently bundled into this WP).

### T011 — Commit + handoff

```bash
composer check-composer-policy
bin/check-package-layers
grep -n "Distribution Extensions" CLAUDE.md
grep -n "genealogy distribution-extension reclassification" docs/specs/extraction-log.md
```

All must succeed. Commit message:

```
chore(genealogy): surface distribution-extension in CLAUDE.md + extraction-log (WP02)

- CLAUDE.md: Layer 6 row no longer lists genealogy
- CLAUDE.md: new "## Distribution Extensions" H2 section
- CLAUDE.md: orchestration row annotated "(distribution-extension)"
- docs/specs/extraction-log.md: 2026-05 reclassification entry added at top
- .github/workflows/split.yml: verified unchanged (line 78 intact)

Refs: genealogy-package-extraction-01KSEFTZ (WP02)
```

Handoff to WP03.

## Verification gate (in lane worktree)

- `git status` shows only `CLAUDE.md` and `docs/specs/extraction-log.md` modified.
- All four greps in T011 return matches.
- `composer check-composer-policy` and `bin/check-package-layers` exit 0.

## Report back with

- The Layer 6 row before/after.
- The verbatim split.yml grep output.
- Pointer to the inserted Distribution Extensions H2 and extraction-log H2 (line numbers).

## Activity Log

_(populated during execution)_
- 2026-05-25T06:19:25Z – claude – shell_pid=141541 – Started implementation via action command
- 2026-05-25T06:21:01Z – claude – shell_pid=141541 – WP02 complete: CLAUDE.md Layer 6 row updated, Distribution Extensions H2 inserted, orchestration row annotated, extraction-log 2026-05 entry added. All gates green.
- 2026-05-25T06:24:02Z – claude – shell_pid=141541 – Opus review: distribution-extension classification flip clean; cms/core/full grep confirmed clean; split.yml unchanged; PR #1580 opened
- 2026-05-26T18:48:37Z – claude – shell_pid=141541 – Done override: Sprint merge to main
