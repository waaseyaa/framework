---
work_package_id: WP01
title: Usage audit — classify every Waaseyaa\Database\ callsite
dependencies: []
requirement_refs:
- FR-001
- FR-002
- FR-003
- NFR-003
- NFR-004
- C-001
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
subtasks:
- T001
- T002
- T003
- T004
- T005
- T006
assignee: ''
agent: "claude"
shell_pid: "131417"
history:
- timestamp: '2026-05-25T00:00:00Z'
  agent: system
  action: Frontmatter added to fix malformed metadata
authoritative_surface: docs/audits/2026-05-database-legacy-usage.md
execution_mode: planning_artifact
owned_files:
- docs/audits/2026-05-database-legacy-usage.md
tags: []
---
# Work Package Prompt: WP01 — Usage audit

**Mission:** `database-legacy-retirement-01KSEFV2`
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)

## CRITICAL — work in the lane worktree

This WP edits no code. The only deliverable is `docs/audits/2026-05-database-legacy-usage.md`. Touch nothing else.

## What you are doing

Inventory every `Waaseyaa\Database\` callsite across `packages/` and `tests/`. Classify each into one of three categories per the rubric. Produce a per-package summary table. Recommend ELIMINATE or RENAME, citing DIR-003. That is the entire WP.

## THE pattern to mirror (read first)

- `docs/audits/2026-05-17-dead-code-baseline-audit.md` — for audit-document shape, sectioning, summary-table cadence.
- `.kittify/charter/charter.md` — for the verbatim DIR-003 wording you must cite.
- `docs/adr/007-database-legacy-package-naming.md` — for the historical reasoning the disposition must engage with.

## Subtasks

### T001 — Inventory (canonical grep)

```bash
grep -rln 'Waaseyaa\\Database\\' packages/ tests/ --include='*.php' > /tmp/dblegacy-files.txt
wc -l /tmp/dblegacy-files.txt
```

Expected: ~243 files. Record exact count in the audit document.

### T002 — Per-file classification

For each file in `/tmp/dblegacy-files.txt`:
1. Open it.
2. Identify the `Waaseyaa\Database\*` symbols used (`use` statements and FQCN references).
3. Apply the rubric in `plan.md` §"Classification rubric":
   - (a) `migrate-to-target` — stable DBAL primitive; namespace-edit-only migration.
   - (b) `intentional-bridge` — load-bearing bridge to legacy Drupal DBAL behaviour; MUST cite the symbol(s) and the reason it cannot be replaced.
   - (c) `misuse-migrate-elsewhere` — wrong abstraction; MUST recommend the target (entity-storage, EntityRepository, raw DBAL) and an effort estimate (`trivial` / `out-of-band-followup`).

If you cannot articulate a load-bearing reason for a (b) classification, it is (a) or (c). The mission's value depends on this rigor.

### T003 — Per-package summary table

Aggregate the per-file classifications into a per-package summary. Columns: package, files touched, (a) count, (b) count, (c) count, recommended action.

### T004 — External-consumer scan

If `/home/fsd42/dev/minoo` exists, run:
```bash
grep -rln 'waaseyaa/database-legacy\|Waaseyaa\\Database\\' /home/fsd42/dev/minoo --include='*.php' --include='*.json' 2>/dev/null
```
Same for `/home/fsd42/dev/claudriel`.

Document every match with classification (a/b/c). If neither repo is accessible, record that explicitly and proceed.

### T005 — Disposition recommendation

Author the final `## Disposition recommendation` section. Two cases:

**Recommend ELIMINATE if all of the following are true:**
- Every (a) callsite has a 1:1 target in the framework's post-retirement DB abstraction (DBAL direct, query builder, `EntityRepository`).
- No (b) classification survived scrutiny.
- No external consumer requires the package by name (or the consumer can migrate in lockstep).

**Recommend RENAME if any one of the following is true:**
- A (b) classification survived scrutiny — there is a real bridge use-case.
- An external consumer's release cadence cannot be coordinated.
- A symbol in the legacy package has no 1:1 target.

The recommendation paragraph MUST cite DIR-003 (Greenfield Removal Policy) by name and ground the choice in the audit data.

### T006 — Commit

```bash
git add docs/audits/2026-05-database-legacy-usage.md
git commit -m "audit(database-legacy): enumerate Waaseyaa\\\\Database\\\\ callsites + recommend disposition (WP01)

Refs: database-legacy-retirement-01KSEFV2 (WP01)
Cites: DIR-003 (Greenfield Removal Policy)"
```

## Verification gate (in lane worktree)

- `docs/audits/2026-05-database-legacy-usage.md` exists.
- Every file from the T001 inventory appears in the audit's per-file classification.
- Per-package summary table is present and totals match the file inventory.
- Disposition recommendation cites DIR-003 by name and is one of ELIMINATE / RENAME.
- `git status` shows only the audit document added.

## Commit + handoff

See T006. Hand off to WP02; WP02's `occurrence_map.yaml` derives from this audit's (a) classification.

## Report back with

- File count from T001.
- (a) / (b) / (c) totals.
- Disposition recommendation (one line + the grounding paragraph).
- Any external consumer surprises.

## Activity Log

_(populated during execution)_
- 2026-05-25T06:16:26Z – claude – shell_pid=131417 – Started implementation via action command
- 2026-05-25T06:21:31Z – claude – shell_pid=131417 – Audit complete: 307 files, 294 (a), 0 (b), 0 (c). Recommendation: ELIMINATE per DIR-003. Commit 3e5ee14f5.
- 2026-05-25T06:43:05Z – claude – shell_pid=131417 – Opus review: ELIMINATE per DIR-003; 13 files deleted; ADR-022 supersedes ADR-007; PR #1581 open; all gates green
- 2026-05-26T18:48:45Z – claude – shell_pid=131417 – Moved to done
