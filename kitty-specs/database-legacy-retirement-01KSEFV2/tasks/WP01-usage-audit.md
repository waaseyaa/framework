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
