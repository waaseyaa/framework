---
work_package_id: "WP01"
title: "Apply charter amendment — Waaseyaa/Anokii track constitutional commitments"
dependencies: []
planning_base_branch: "main"
merge_target_branch: "main"
branch_strategy: "Planning artifacts were generated on main; the documentation edit must merge back into main as a single atomic commit."
subtasks:
  - "T001"
  - "T002"
  - "T003"
  - "T004"
  - "T005"
  - "T006"
  - "T007"
phase: "Wave 0 — Foundation"
assignee: ""
agent: ""
shell_pid: ""
history:
  - timestamp: "2026-05-25T02:29:13Z"
    agent: "system"
    action: "Prompt generated via spec-kitty specify (manual content authored by spec-production-session)"
---

# Work Package Prompt: WP01 — Apply charter amendment

**Mission:** `charter-amendment-anokii-track-01KSEFE0`
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)

## CRITICAL — work in the lane worktree

This is a single-file documentation edit on `.kittify/charter/charter.md`. The lane worktree should be created via `spec-kitty implement` for traceability even though the edit is small. All work happens inside the lane; commit goes back to `main` at merge time.

## What you are doing

The 2026-05-24 spec-production session settled five architectural and constitutional commitments by direct author authorization (four `AskUserQuestion` answers). The existing `.kittify/charter/charter.md` does not record them. This WP ratifies the commitments by editing the charter to add the exact text specified in `../plan.md`.

You are NOT writing the amendment yourself. You are applying the amendment that has already been written. Read `../plan.md` blocks §1, §2, §3, §4 and insert them at the documented anchor points.

## THE pattern to mirror (read these before editing)

- The existing charter at `.kittify/charter/charter.md` — read end-to-end so the inserted directives match the existing tone (authoritative MUST/MUST NOT statements, concrete consequences, no hedging).
- DIR-001..DIR-003 in the existing `## Project Directives` section — the new directives 4..8 mirror their list-item formatting (number, period, space, capitalized rule statement on the first line, sub-bullets where applicable).
- The `## Amendment Process` section — confirms that amendments are expected to evolve the charter; you are following the documented process (the only deviation is skipping the `spec-kitty charter generate --from-interview --force` step, justified in `../spec.md` because the interview transcript predates these decisions).

## Subtasks

### T001 — Read current charter and confirm anchors

Open `.kittify/charter/charter.md`. Identify and note the line numbers of:
1. The last line of `## Branch Strategy` (ends with "...takes no opinion on production deployment topology.")
2. The opening of `## Governance Activation`
3. The last line of directive 3 in `## Project Directives` (ends with "...the binding force of this directive comes from its text.")
4. The opening of `## Reference Index`
5. The last line of `## Amendment Process` (ends with "...as the framework matures from alpha through v1.0 and beyond.")
6. The opening of `## Exception Policy`
7. The `Generated:` line near the top (currently line 5)

Confirm these anchors match the current file. If the charter has been edited since `../plan.md` was written and the anchors no longer exist as documented, STOP and surface the discrepancy — do not improvise.

### T002 — Insert Framework vs Distribution Architecture section

Insert `../plan.md` §1 block verbatim between the end of `## Branch Strategy` and the start of `## Governance Activation`. Preserve one blank line above and below the inserted `## Framework vs Distribution Architecture` heading per existing convention.

### T003 — Append directives 4..8

After directive 3 closes and before `## Reference Index` opens, insert `../plan.md` §2 block verbatim. The block contains five directives (numbered 4 through 8). Each is a top-level numbered list item with sub-bullets where applicable; formatting matches the existing 1/2/3 style.

### T004 — Insert Amendment History section

Insert `../plan.md` §3 block verbatim between the end of `## Amendment Process` and the start of `## Exception Policy`. Substitute the placeholder `<HH:MM:SS>` in the timestamp with the actual UTC time at the moment of the edit (use `date -u +"%H:%M:%S"`).

### T005 — Edit `Generated:` line

Replace `Generated: 2026-04-27T04:26:37Z` with `Generated: 2026-04-27T04:26:37Z; Last amended: 2026-05-24T<HH:MM:SS>Z` substituting the actual UTC `HH:MM:SS` at edit time. The amendment-date prefix is `2026-05-24` regardless of the literal commit time — it captures the day the constitutional decisions were made by author authorization, not the day the commit lands.

### T006 — Verification

Run from the repo root:

```
grep -c "^## Framework vs Distribution Architecture" .kittify/charter/charter.md          # must print 1
grep -c "^## Amendment History" .kittify/charter/charter.md                                # must print 1
grep -c "Last amended:" .kittify/charter/charter.md                                        # must print 1
awk '/^## Project Directives/,/^## Reference Index/' .kittify/charter/charter.md | grep -cE "^[0-9]+\\. "  # must print 8 (1..3 existing + 4..8 new)
git diff .kittify/charter/charter.md | grep -cE "^-[^-]"                                    # must print exactly 1 (the original Generated: line)
```

If any check fails: fix the file and re-run all five. Do not commit until all pass.

### T007 — Commit

```
git add .kittify/charter/charter.md
git commit -m "docs(charter): ratify Waaseyaa/Anokii constitutional commitments (charter-amendment-anokii-track-01KSEFE0)"
```

Single atomic commit. Do NOT stage any other file. Do NOT amend a previous commit.

## Verification gate (in lane worktree)

All five T006 checks pass. `git status` shows clean working tree after T007 (no other modifications). `git log -1` shows the commit message above.

## Commit + handoff

After T007 succeeds:

```
cd /home/fsd42/dev/waaseyaa
spec-kitty agent tasks mark-status T001 T002 T003 T004 T005 T006 T007 --status done --mission charter-amendment-anokii-track-01KSEFE0
spec-kitty agent tasks move-task WP01 --to for_review --mission charter-amendment-anokii-track-01KSEFE0 --note "Charter amendment applied; all five grep verifications pass. Diff is purely additive except the one-line Generated: replacement."
```

## Report back with

- The line numbers chosen for each insertion (so the reviewer can spot-check anchor accuracy).
- The exact `<HH:MM:SS>` substituted into the amendment timestamp.
- Confirmation that all five T006 checks passed.
- The commit SHA.

## Activity Log

(populated by the implementing agent as work progresses)
- 2026-05-25T04:16:56Z – unknown – Implementer applied verbatim plan.md §1-§4 blocks; commit 9a9e6eee9
- 2026-05-25T04:18:14Z – unknown – All 7 subtasks complete; 5 grep verification checks pass; commit 9a9e6eee9 landed on origin/main
- 2026-05-25T04:18:16Z – unknown – Opus review: tone consistency confirmed; cross-references accurate; byte-for-byte match with plan.md blocks; diff purely additive except one-line Generated: replacement
- 2026-05-25T04:20:33Z – unknown – Wave 0 complete. Charter live on origin/main as commit 9a9e6eee9. DIR-004..DIR-008 ratified. All Wave 1+ missions unblocked.
