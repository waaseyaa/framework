---
work_package_id: "WP02"
title: "Publish SUCCESSION.md"
dependencies: []
planning_base_branch: "main"
merge_target_branch: "main"
branch_strategy: "Planning artifacts were generated on main; completed changes must merge back into main."
subtasks:
  - "T005"
  - "T006"
  - "T007"
phase: "Tier 1 — Practical pre-conditions"
assignee: ""
agent: ""
shell_pid: ""
history:
  - timestamp: "2026-05-24T00:00:00Z"
    agent: "system"
    action: "Prompt generated via /spec-kitty.tasks"
---

# Work Package Prompt: WP02 — Publish SUCCESSION.md

## CRITICAL — work in the lane worktree

```
cd /home/fsd42/dev/waaseyaa/.worktrees/succession-framework-tier1-publishing-01KSEFV6-lane-b
```

(Exact path is printed by `spec-kitty agent action implement WP02`.) Parallelisable with WP01 (different file; no shared state).

## What you are doing

Publishing the framework's `SUCCESSION.md` at the repo root. This is the multi-tier continuity narrative — Tier 0 (already in place) through Tier 4 (long-horizon governance vehicle). The companion to `MAINTAINERS.md`: where MAINTAINERS.md is the operational roster, SUCCESSION.md is the institutional narrative.

The file content is fully specified in `../plan.md` §2 — apply it verbatim. No implementer substitutions.

## THE pattern to mirror (read these before editing)

- `.kittify/charter/charter.md` — match the authoritative tone.
- `../plan.md` §2 — the full file content. Apply verbatim.
- `../spec.md` §"Why this mission exists" — re-read for the strategic framing.

## Subtasks

### T005 — Write `/SUCCESSION.md`

Apply the `../plan.md` §2 block verbatim to `/SUCCESSION.md` at the repo root. No substitutions. The file is self-contained: Tier 0 through Tier 4 paragraphs, the procurement-facing narrative, the glossary.

### T006 — Verify content

Run the verification checks:

- `test -f /home/fsd42/dev/waaseyaa/SUCCESSION.md` → exit 0
- `grep -cE "^## Tier [01234]" SUCCESSION.md` → `5` (one heading per tier; matches the regex `## Tier 0`, `## Tier 1`, `## Tier 2`, `## Tier 3`, `## Tier 4`)
- `grep -c "DIR-006" SUCCESSION.md` → at least `1`
- `grep -c "MAINTAINERS.md" SUCCESSION.md` → at least `1`
- `grep -c "OCAP" SUCCESSION.md` → at least `1`
- `grep -cE "TBD|TODO|_placeholder_|<placeholder>|<<[A-Z_]+>>" SUCCESSION.md` → `0`

### T007 — Commit

If all T006 checks pass, commit:

```
git add SUCCESSION.md
git commit -m "docs(governance): publish SUCCESSION.md (succession-framework-tier1-publishing-01KSEFV6 WP02)"
```

NO other files staged.

## Verification gate (in lane worktree)

All T006 checks pass.

## Commit + handoff

After T007, move WP to for_review:

```
cd /home/fsd42/dev/waaseyaa
spec-kitty agent tasks mark-status T005 T006 T007 --status done --mission succession-framework-tier1-publishing-01KSEFV6
spec-kitty agent tasks move-task WP02 --to for_review --mission succession-framework-tier1-publishing-01KSEFV6 --note "SUCCESSION.md published; all verifications pass."
```

WP02 has no downstream WPs in this mission. After approval and merge, this WP contributes to mission close.

## Report back with

- Confirmation that all five tier headings match the `^## Tier [01234]` regex (with the grep -c output).
- Confirmation that no placeholder tokens remain.
- Any prose drift you noticed against the `../plan.md` §2 block (there should be none — the block is the implementer's contract).

## Activity Log

(populated by the implementing agent as work progresses)
- 2026-05-25T04:57:42Z – unknown – Opus review: markdown-only mission, verbatim from plan.md §1/§2. WP03/WP04 deferral markers documented per spec.
