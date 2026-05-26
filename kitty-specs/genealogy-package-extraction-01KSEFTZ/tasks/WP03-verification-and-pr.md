---
work_package_id: WP03
title: Verification gates + PR
dependencies:
- WP02
requirement_refs:
- FR-009
- NFR-002
- NFR-003
- C-001
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
subtasks: []
history: []
authoritative_surface: kitty-specs/genealogy-package-extraction-01KSEFTZ/
execution_mode: planning_artifact
owned_files:
- kitty-specs/genealogy-package-extraction-01KSEFTZ/**
tags: []
agent: "claude"
shell_pid: "145396"
---
# Work Package Prompt: WP03 — Verification gates + PR

**Mission:** `genealogy-package-extraction-01KSEFTZ`
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)
**Depends on:** WP02.

## CRITICAL — work in the lane worktree

This WP edits no source files. It runs the full acceptance command set and opens the PR.

## What you are doing

Run all eight acceptance commands. Verify each. Open the PR.

## Subtasks

### T012 — Acceptance commands

```bash
git diff --stat origin/main..HEAD
grep -n "Distribution Extensions" CLAUDE.md
grep -n "genealogy distribution-extension reclassification" docs/specs/extraction-log.md
grep -c "DIR-004" docs/specs/genealogy.md
grep "waaseyaa/genealogy" packages/cms/composer.json packages/core/composer.json packages/full/composer.json
composer check-composer-policy
bin/check-package-layers
grep -n "packages/genealogy" .github/workflows/split.yml
```

Capture full output of each. The first command must show only:
- `packages/genealogy/composer.json`
- `docs/specs/genealogy.md`
- `CLAUDE.md`
- `docs/specs/extraction-log.md`

(Optionally `.github/workflows/split.yml` if a no-op format normalisation was needed — most lanes will have it untouched.)

If any other file appears, BLOCK and investigate before opening the PR.

### T013 — PR

Title:

```
chore(genealogy): reclassify as distribution-extension package (DIR-004 first extraction)
```

Body template:

```markdown
## Summary

First execution of the framework-vs-distribution boundary codified in charter
directive DIR-004 (mission: `charter-amendment-anokii-track-01KSEFE0`).

`waaseyaa/genealogy` is reclassified from *framework Layer 6 package* to
*distribution-extension package*. Metadata-only flip — no source code,
namespace, or autoload changes. Package keeps its Packagist URL and PSR-4
prefix `Waaseyaa\Genealogy\`. Consumers (notably Minoo) see zero change.

Five files touched:
- `packages/genealogy/composer.json` — description leads with
  "Distribution-extension package —"
- `docs/specs/genealogy.md` — top-of-file DIR-004 banner block
- `CLAUDE.md` — Layer 6 row stripped, new `## Distribution Extensions` H2,
  orchestration-row annotation
- `docs/specs/extraction-log.md` — `2026-05 — genealogy distribution-extension
  reclassification` entry
- `.github/workflows/split.yml` — verified unchanged (line 78 intact)

Precedent for future Bimaaji- and Minoo-specific extractions. See
`docs/specs/extraction-log.md` for the playbook (rationale → scope →
what-changed → downstream-impact → layer-guard-reasoning → follow-ups).

## Mission trace

- Mission: `genealogy-package-extraction-01KSEFTZ`
- Charter directive: DIR-004 (Framework vs Distribution)
- Precedent entries: `groups` (2026-04), `mail-api` (2026-04), `geo-distance` (2026-04)

## Test plan

- [ ] `composer check-composer-policy` exits 0
- [ ] `bin/check-package-layers` exits 0
- [ ] `git diff --stat origin/main..HEAD` shows only the five enumerated files
- [ ] CLAUDE.md Layer 6 row no longer contains `genealogy`
- [ ] CLAUDE.md `## Distribution Extensions` H2 present
- [ ] `docs/specs/genealogy.md` opens with DIR-004 banner
- [ ] `docs/specs/extraction-log.md` opens with the new reclassification H2
- [ ] No metapackage edits (cms / core / full unchanged)
```

### T014 — CI verification

After PR open, watch CI. All gates must be green:

- `composer check-composer-policy` (CP001, CP002, CP003, CP006, CP-NEW).
- `bin/check-package-layers`.
- `bin/check-dead-code`.
- `bin/check-getquery-bindings` (no new entries — this WP touches no production PHP).
- PHPUnit (no test changes — should be no-op delta).

If any gate fails, transition to BLOCKED, capture the failure, and do not merge.

## Verification gate (in lane worktree)

- All eight acceptance commands return expected results.
- PR is open with the title and body shape mandated above.
- CI is green.

## Report back with

- The git diff stat (verbatim).
- The PR URL.
- A confirmation that CI is green at PR open.

## Activity Log

_(populated during execution)_
- 2026-05-25T06:21:09Z – claude – shell_pid=145396 – Started implementation via action command
- 2026-05-25T06:23:30Z – claude – shell_pid=145396 – All 8 acceptance commands passed. PR opened: https://github.com/waaseyaa/framework/pull/1580. Pre-push hooks (spec-drift, phpunit, composer-policy, phpstan) all green.
- 2026-05-25T06:24:05Z – claude – shell_pid=145396 – Opus review: distribution-extension classification flip clean; cms/core/full grep confirmed clean; split.yml unchanged; PR #1580 opened
- 2026-05-26T18:48:39Z – claude – shell_pid=145396 – Moved to done
