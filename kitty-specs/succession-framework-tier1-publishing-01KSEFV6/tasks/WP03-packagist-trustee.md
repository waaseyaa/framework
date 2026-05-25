---
work_package_id: "WP03"
title: "Designate Packagist trustee on waaseyaa/* namespace and record in MAINTAINERS.md"
dependencies: ["WP01"]
planning_base_branch: "main"
merge_target_branch: "main"
branch_strategy: "Planning artifacts were generated on main; completed changes must merge back into main."
subtasks:
  - "T008"
  - "T009"
  - "T010"
  - "T011"
phase: "Tier 1 — Practical pre-conditions"
assignee: ""
agent: ""
shell_pid: ""
history:
  - timestamp: "2026-05-24T00:00:00Z"
    agent: "system"
    action: "Prompt generated via /spec-kitty.tasks"
---

# Work Package Prompt: WP03 — Designate Packagist trustee

## CRITICAL — work in the lane worktree

```
cd /home/fsd42/dev/waaseyaa/.worktrees/succession-framework-tier1-publishing-01KSEFV6-lane-c
```

(Exact path is printed by `spec-kitty agent action implement WP03`.) Depends on WP01 (`MAINTAINERS.md` must exist with marker tokens in place).

## What you are doing

Designating a SECOND Packagist account with publish rights on the `waaseyaa/*` namespace, then recording that trustee in `MAINTAINERS.md` by substituting the two `<<TRUSTEE_PACKAGIST_ACCOUNT>>` marker tokens and filling the affiliation cell.

This is a configuration WP plus a documentation substitution. The configuration portion is operational (Packagist owner-list update); the documentation portion is a small edit to `MAINTAINERS.md`.

## Decision deferred to you (with Russell)

**Russell selects the trustee account at WP03 execution time.** Do NOT proceed past T008 without a confirmed trustee selection.

Selection criteria (from `../spec.md` §"Decisions deferred to implementer"):

- Active Packagist account.
- 2FA-enabled.
- Held by an individual or organisation Russell trusts to publish security fixes on `waaseyaa/*` if the primary maintainer is unavailable for more than 14 days.
- Candidate categories (not exhaustive; Russell's call): OIATC technical lead; a long-term external contributor with publish history on related namespaces; an academic-institution partner.

Preference order (from `../spec.md` C-005): **preserve OCAP audit lineage > minimise vendor lock-in > don't break codified policy gates.** Concretely for this WP: the trustee is an ADDITIONAL publisher, NOT a transfer of ownership. The namespace owner remains the primary maintainer; the trustee's Packagist account is appended to the owner list on each `waaseyaa/*` package.

## THE pattern to mirror (read these before editing)

- `../plan.md` §3 — full operational + documentation steps.
- `../spec.md` FR-007, NFR-004 — the trustee MUST be additive, not a transfer.
- `MAINTAINERS.md` "Packagist trustee" section (from WP01) — the documentation lives here.

## Subtasks

### T008 — Confirm trustee selection with Russell

If Russell has not yet named the trustee, surface this as a blocker and pause the WP. Do NOT improvise a candidate.

Once confirmed, record (locally — you will write to MAINTAINERS.md in T010):

- Trustee Packagist username.
- Trustee affiliation (one phrase — e.g. "OIATC", "Independent contributor", "<academic institution>").

### T009 — Configure Packagist owner list (operational)

This step requires Packagist credentials held by Russell. If Russell is driving this step, you wait for confirmation. If Russell delegates and you have credentials:

1. Confirm the trustee account exists on packagist.org and has 2FA enabled (the trustee should confirm this directly, not just Russell).
2. On packagist.org, log in as the primary maintainer.
3. For each published `waaseyaa/*` package, visit the package page → "Maintainers" tab → add the trustee account by Packagist username. Start with `waaseyaa/framework`. Iterate through the full list of currently-published `waaseyaa/*` packages (use `composer show -a 'waaseyaa/*' --available` or the packagist.org search to enumerate).
4. Spot-check by visiting packagist.org/packages/waaseyaa/framework and confirming the trustee appears in the maintainers list.

If you do NOT have credentials and Russell is unavailable, pause the WP after T010 (documentation can be written before T009 completes, but the WP cannot move to for_review until T009 is confirmed done).

### T010 — Substitute marker tokens in MAINTAINERS.md

In `/MAINTAINERS.md`:

1. Replace both occurrences of `<<TRUSTEE_PACKAGIST_ACCOUNT>>` with the trustee's Packagist username (the literal username — no `@` prefix, no backticks added; backticks are already in the surrounding text).
2. In the "Current maintainers" table, replace the placeholder text `(recorded at trustee designation by WP03 of `succession-framework-tier1-publishing-01KSEFV6`)` in the trustee row's "Affiliation" cell with the actual affiliation phrase from T008.

Run:

- `grep -c "<<TRUSTEE_PACKAGIST_ACCOUNT>>" MAINTAINERS.md` → `0`
- `grep -c "<the trustee username>" MAINTAINERS.md` → at least `2` (substitute the actual username; expect at least 2 occurrences from the two original marker positions)

### T011 — Verify and commit

Run the verification checks:

- `grep -c "<<TRUSTEE_PACKAGIST_ACCOUNT>>" MAINTAINERS.md` → `0`
- `grep -c "recorded at trustee designation by WP03" MAINTAINERS.md` → `0`
- The trustee account is visible on packagist.org/packages/waaseyaa/framework "Maintainers" tab (manual check — paste the URL into the activity log with a note "trustee confirmed visible").
- `grep -c "<<NATION_HOSTED_MIRROR_URL>>" MAINTAINERS.md` → `2` (unchanged — WP04 substitutes)

If all checks pass, commit:

```
git add MAINTAINERS.md
git commit -m "docs(governance): record Packagist trustee in MAINTAINERS.md (succession-framework-tier1-publishing-01KSEFV6 WP03)"
```

NO other files staged. The Packagist owner-list change (T009) is external to the repo and is verified by manual check, not by a commit.

## Verification gate (in lane worktree)

All T011 checks pass. Packagist owner-list update confirmed via spot-check on packagist.org.

## Commit + handoff

After T011, move WP to for_review:

```
cd /home/fsd42/dev/waaseyaa
spec-kitty agent tasks mark-status T008 T009 T010 T011 --status done --mission succession-framework-tier1-publishing-01KSEFV6
spec-kitty agent tasks move-task WP03 --to for_review --mission succession-framework-tier1-publishing-01KSEFV6 --note "Trustee <username> designated on waaseyaa/* Packagist namespace; MAINTAINERS.md substituted; spot-check confirmed."
```

After approval and merge, WP04 (Nation-hosted mirror) is unblocked.

## Report back with

- The chosen trustee Packagist username and affiliation.
- The list of `waaseyaa/*` packages where the trustee was added to the maintainers list.
- Confirmation that the trustee is an ADDITIONAL publisher, not a transfer (the primary maintainer still appears in every package's maintainer list).
- The packagist.org URL used for the spot-check confirmation.

## Activity Log

(populated by the implementing agent as work progresses)
- 2026-05-25T04:57:45Z – unknown – Opus review: markdown-only mission, verbatim from plan.md §1/§2. WP03/WP04 deferral markers documented per spec.
