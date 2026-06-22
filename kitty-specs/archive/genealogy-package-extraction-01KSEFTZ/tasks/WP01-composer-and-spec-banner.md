---
work_package_id: WP01
title: Composer description flip + spec banner + metapackage pre-flight grep
dependencies: []
requirement_refs:
- FR-001
- FR-002
- FR-007
- NFR-001
- NFR-002
- NFR-004
- C-001
- C-002
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
base_branch: kitty/mission-genealogy-package-extraction-01KSEFTZ
base_commit: 6fe41e2dbfa4a096553c566232c553b0bad8cb71
created_at: '2026-05-25T06:18:23.129679+00:00'
subtasks: []
shell_pid: "139060"
history: []
authoritative_surface: packages/genealogy/composer.json
execution_mode: code_change
owned_files:
- packages/genealogy/composer.json
- docs/specs/genealogy.md
tags: []
agent: "claude"
---
# Work Package Prompt: WP01 — Composer description + spec banner + metapackage pre-flight grep

**Mission:** `genealogy-package-extraction-01KSEFTZ`
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)

## CRITICAL — work in the lane worktree

This WP is `documentation` execution mode. The lane worktree is your isolated workspace. Do not touch any file outside `owned_files` (`packages/genealogy/composer.json`, `docs/specs/genealogy.md`).

## What you are doing

Three things, in this exact order:

1. **Pre-flight grep on framework metapackages** (FR-007). If `cms`, `core`, or `full` require `waaseyaa/genealogy`, you BLOCK the mission instead of silently editing them.
2. **Flip the composer description** in `packages/genealogy/composer.json` so Packagist reflects the new classification.
3. **Insert a DIR-004 banner block** at the top of `docs/specs/genealogy.md`.

## THE pattern to mirror (read first)

- `docs/specs/extraction-log.md` `2026-04 — waaseyaa/groups package extraction` entry — same shape as the WP02 extraction-log entry will take (so your banner copy here should harmonise with that vocabulary).
- `packages/groups/composer.json` (in the framework if still present, or the split mirror) — for reference on description tone.
- `CLAUDE.md` orchestration table — confirm `packages/genealogy/*` row exists before WP02 touches it.

## Subtasks

### T001 — Pre-flight grep (FR-007, blocking gate)

Run:

```bash
grep -n "waaseyaa/genealogy" packages/cms/composer.json packages/core/composer.json packages/full/composer.json
```

Expected exit code: 1 (no matches). Capture the full output (or "no matches") verbatim in the activity log.

If any match returns:
1. Stop. Do NOT edit anything.
2. Transition the WP to BLOCKED.
3. File an out-of-band note describing which metapackage requires genealogy and recommend a follow-up mission to decide whether the metapackage should drop the dep or whether the reclassification should be deferred.

### T002 — composer.json description edit (FR-001)

Open `packages/genealogy/composer.json`. Locate the `description` field. Current value (verbatim):

```
"description": "Genealogy domain entities, graph traversal, and public SSR for Waaseyaa",
```

Replace with:

```
"description": "Distribution-extension package — Indigenous genealogy entities, graph traversal, and public SSR for Waaseyaa-based distributions",
```

Do not modify any other field. Preserve `sort-packages: true`, autoload, repositories, require, require-dev, extra, scripts, branch-alias.

### T003 — spec banner insertion (FR-002)

Open `docs/specs/genealogy.md`. Insert the following 5-line banner block **before** the existing `# Genealogy package (v0.1)` H1 (and after any frontmatter / Generated marker if present):

```markdown
> **Distribution-extension package** — `waaseyaa/genealogy` is a *distribution-extension*,
> not framework substrate. Per charter directive DIR-004 (Framework vs Distribution
> Architecture), domain content like Indigenous family lineage modelling is delivered
> as a separately-versioned package consumers opt into, and is **not** required by
> `waaseyaa/core`, `waaseyaa/cms`, or `waaseyaa/full`. See
> `docs/specs/extraction-log.md` for the reclassification record.
```

Leave a single blank line between the banner and the H1.

### T004 — Verification

Run, in order:

```bash
composer check-composer-policy
bin/check-package-layers
grep -c "DIR-004" docs/specs/genealogy.md          # >= 1
grep -c "Distribution-extension package" packages/genealogy/composer.json   # exactly 1
```

All four must succeed. Capture transcripts in the activity log.

### T005 — Commit + handoff

Stage the two owned files. Commit message:

```
chore(genealogy): flip classification to distribution-extension (WP01)

- composer.json description leads with "Distribution-extension package —"
- docs/specs/genealogy.md gains DIR-004 banner block
- pre-flight grep confirmed: cms, core, full do not require waaseyaa/genealogy

Refs: genealogy-package-extraction-01KSEFTZ (WP01)
Cites: charter directive DIR-004 (Framework vs Distribution)
```

Handoff to WP02 once green.

## Verification gate (in lane worktree)

- `composer check-composer-policy` exits 0.
- `bin/check-package-layers` exits 0.
- `git status` shows only `packages/genealogy/composer.json` and `docs/specs/genealogy.md` modified.

## Commit + handoff

See T005. Do not open a PR — WP03 owns the PR.

## Report back with

- The pre-flight grep transcript (verbatim).
- The composer.json description before/after diff.
- The spec banner exact text inserted.
- Verification command exit codes.

## Activity Log

_(populated during execution)_
- 2026-05-25T06:18:27Z – claude – shell_pid=139060 – Assigned agent via action command
- 2026-05-25T06:19:18Z – claude – shell_pid=139060 – WP01 complete: composer.json description flipped, DIR-004 banner inserted in genealogy.md, all verification gates green
- 2026-05-25T06:23:59Z – claude – shell_pid=139060 – Opus review: distribution-extension classification flip clean; cms/core/full grep confirmed clean; split.yml unchanged; PR #1580 opened
- 2026-05-26T18:48:34Z – claude – shell_pid=139060 – Done override: Sprint merge to main
