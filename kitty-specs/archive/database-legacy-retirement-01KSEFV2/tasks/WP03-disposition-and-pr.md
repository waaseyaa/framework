---
work_package_id: WP03
title: Disposition (ELIMINATE or RENAME) + ADR + PR
dependencies:
- WP02
requirement_refs:
- FR-006
- FR-007
- FR-008
- FR-009
- NFR-002
- NFR-003
- C-001
- C-003
- C-004
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
subtasks:
- T013
- T014
- T015
- T016
- T017
- T018
assignee: ''
agent: "claude"
shell_pid: "154271"
history:
- timestamp: '2026-05-25T00:00:00Z'
  agent: system
  action: Frontmatter added to fix malformed metadata
authoritative_surface: packages/database-legacy/
execution_mode: code_change
owned_files:
- packages/database-legacy/
- .github/workflows/split.yml
- CLAUDE.md
- composer.json
- docs/adr/
- CHANGELOG.md
tags: []
---
# Work Package Prompt: WP03 — Disposition (ELIMINATE or RENAME) + ADR + PR

**Mission:** `database-legacy-retirement-01KSEFV2`
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)
**Depends on:** WP02.

## CRITICAL — work in the lane worktree

This WP makes the final disposition. The reviewer-of-record reads WP01's audit, confirms the path (ELIMINATE default per DIR-003; RENAME only if the audit triggers a RENAME condition), and executes the corresponding steps in `plan.md`.

## What you are doing

One of two paths:
- **Path A — ELIMINATE** (default per DIR-003).
- **Path B — RENAME** (fallback if the audit triggers it).

The chosen path executes its full set of file edits, writes a new ADR, appends a `## Disposition` section to the audit, updates the CHANGELOG, opens the PR.

## THE pattern to mirror (read first)

- The WP01 audit's `## Disposition recommendation` section — this is the input that drives the path selection.
- `docs/adr/007-database-legacy-package-naming.md` — the ADR being superseded.
- `docs/adr/` — for the highest existing ADR number (your new ADR's number is `<that> + 1`, zero-padded to 3 digits).
- CHANGELOG.md — for the `### Removed` / `### Changed` cadence used by prior releases.

## Subtasks

### T013 — Path decision (recorded in activity log)

Read `docs/audits/2026-05-database-legacy-usage.md` §"Disposition recommendation".

Decide:
- **ELIMINATE** — default. Take this unless the audit explicitly triggers a RENAME condition.
- **RENAME** — only if the audit recommends it (a (b) classification survived, an external consumer cannot coordinate, or a symbol has no 1:1 target).

Record the decision and the audit-grounded rationale in this WP's activity log before any edits.

### T014 — Path A — ELIMINATE

Skip to T015 if Path B was chosen.

Execute every step in `plan.md` §"Path A — ELIMINATE":
1. `git rm -r packages/database-legacy/`
2. Edit `.github/workflows/split.yml` — remove the `packages/database-legacy` matrix entry (currently line 20).
3. Edit `CLAUDE.md` Layer 0 table cell — remove the `database-legacy` token.
4. Edit `CLAUDE.md` orchestration table — remove any row containing `database-legacy`.
5. Edit root `composer.json` — remove the path-repository entry for `./packages/database-legacy/` if present.
6. Write new ADR `docs/adr/0NN-database-legacy-retirement.md` (NN = highest existing + 1). Status: `Accepted (supersedes ADR-007)`. Required sections: Context (audit summary + DIR-003 grounding), Decision (ELIMINATE), Consequences (external consumer migration in lockstep; CHANGELOG entry), References (ADR-007, mission slug, audit file path).
7. Append `## Disposition` section to `docs/audits/2026-05-database-legacy-usage.md` per `plan.md` shape.
8. Add CHANGELOG.md entry:
   ```
   ### Removed
   - `waaseyaa/database-legacy` (no replacement; DBAL is the canonical DB abstraction). See ADR-0NN.
   ```

### T015 — Path B — RENAME

Skip if Path A was chosen.

Execute every step in `plan.md` §"Path B — RENAME":
1. `git mv packages/database-legacy packages/database-bridge`
2. Edit `packages/database-bridge/composer.json` — `name` to `waaseyaa/database-bridge`; refresh `description` (drop "Interim until Doctrine migration"); preserve `Waaseyaa\Database\` autoload prefix (C-003, FR-008).
3. Update every package that requires `waaseyaa/database-legacy`:
   ```bash
   grep -l '"waaseyaa/database-legacy"' packages/*/composer.json
   ```
   Edit each to `waaseyaa/database-bridge` at the same `^<current-tag>` constraint.
4. Edit `.github/workflows/split.yml` — change the matrix entry to `packages/database-bridge` / `database-bridge`.
5. Edit `CLAUDE.md` Layer 0 cell — replace `database-legacy` with `database-bridge`.
6. Edit `CLAUDE.md` orchestration table — same replacement.
7. Write new ADR `docs/adr/0NN-database-legacy-rename.md`. Status: `Accepted (supersedes ADR-007)`. Context: audit summary + bridge justification. Decision: RENAME. References: ADR-007, mission slug, audit file path.
8. Append `## Disposition` section to the audit per `plan.md` shape.
9. CHANGELOG.md entry:
   ```
   ### Changed
   - `waaseyaa/database-legacy` renamed to `waaseyaa/database-bridge`; PSR-4 namespace `Waaseyaa\Database\` preserved. See ADR-0NN.
   ```

### T016 — Verification commands

Common (both paths):
```bash
composer check-composer-policy
bin/check-package-layers
bin/check-getquery-bindings
./vendor/bin/phpunit
grep -c "database-legacy" CLAUDE.md   # 0
```

ELIMINATE-only:
```bash
test ! -d packages/database-legacy
grep "database-legacy" .github/workflows/split.yml   # no matches
```

RENAME-only:
```bash
test -f packages/database-bridge/composer.json
grep "waaseyaa/database-bridge" .github/workflows/split.yml | wc -l   # 1
grep -l '"waaseyaa/database-legacy"' packages/*/composer.json   # no matches
```

All must succeed.

### T017 — PR

Title (ELIMINATE):
```
chore(database-legacy): eliminate package per DIR-003 (audit-driven retirement)
```

Title (RENAME):
```
chore(database-legacy): rename to waaseyaa/database-bridge (audit-driven; ADR-007 superseded)
```

Body cites:
- DIR-003 (Greenfield Removal Policy).
- The audit document path (`docs/audits/2026-05-database-legacy-usage.md`).
- ADR-007 (superseded) and the new ADR (`docs/adr/0NN-...`).
- Prior-art branches `audit/dbal-migration` and `chore/remove-database-legacy` (link or note "branch was paused; this mission completes the work").
- Mission slug `database-legacy-retirement-01KSEFV2`.

For ELIMINATE the body MUST include a `## Breaking Change` section announcing the removal and listing any external consumer that needs to migrate in lockstep (sourced from the audit's external-consumer scan).

### T018 — CI verification

After PR open:
- `composer check-composer-policy` (CP001, CP002, CP003, CP006, CP-NEW).
- `bin/check-package-layers`.
- `bin/check-dead-code`.
- `bin/check-getquery-bindings` (CRITICAL — no new offenders).
- PHPUnit (all suites).

If any gate fails, transition to BLOCKED and do not merge.

## Verification gate (in lane worktree)

- Path decision recorded in activity log with audit-grounded rationale.
- All T016 commands exit 0 / green for the chosen path.
- New ADR exists and Status reads "Accepted (supersedes ADR-007)".
- `## Disposition` section appended to the audit.
- CHANGELOG entry present.

## Report back with

- Path chosen (ELIMINATE or RENAME).
- New ADR number and path.
- PR URL.
- CI status at PR open.

## Activity Log

_(populated during execution; record the path decision and its audit-grounded rationale FIRST, before any edits)_
- 2026-05-25T06:27:59Z – claude – shell_pid=154271 – Started implementation via action command
- 2026-05-25T06:43:11Z – claude – shell_pid=154271 – Opus review: ELIMINATE per DIR-003; 13 files deleted; ADR-022 supersedes ADR-007; PR #1581 open; all gates green
- 2026-05-26T18:48:50Z – claude – shell_pid=154271 – Done override: Sprint merge to main
