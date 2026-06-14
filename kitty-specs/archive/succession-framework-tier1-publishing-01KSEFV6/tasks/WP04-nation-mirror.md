---
work_package_id: "WP04"
title: "Set up Nation-hosted mirror and record in MAINTAINERS.md"
dependencies: ["WP01", "WP03"]
planning_base_branch: "main"
merge_target_branch: "main"
branch_strategy: "Planning artifacts were generated on main; completed changes must merge back into main."
subtasks:
  - "T012"
  - "T013"
  - "T014"
  - "T015"
  - "T016"
phase: "Tier 1 — Practical pre-conditions"
assignee: ""
agent: ""
shell_pid: ""
history:
  - timestamp: "2026-05-24T00:00:00Z"
    agent: "system"
    action: "Prompt generated via /spec-kitty.tasks"
---

# Work Package Prompt: WP04 — Set up Nation-hosted mirror

## CRITICAL — work in the lane worktree

```
cd /home/fsd42/dev/waaseyaa/.worktrees/succession-framework-tier1-publishing-01KSEFV6-lane-d
```

(Exact path is printed by `spec-kitty agent action implement WP04`.) Depends on WP01 + WP03 (`MAINTAINERS.md` must exist with `<<NATION_HOSTED_MIRROR_URL>>` markers still in place; WP03 must have already substituted its own markers without disturbing this WP's).

## What you are doing

Configuring a read-only mirror of `github.com/waaseyaa/framework` on a Nation-controlled FOSS Git forge (Gitea or Forgejo), then recording that mirror in `MAINTAINERS.md` by substituting the two `<<NATION_HOSTED_MIRROR_URL>>` marker tokens and filling the "Forge software" cell.

The mirror is a continuity artifact: read-only from the mirror side in steady state, becomes the new origin only in the GitHub-unavailable recovery procedure documented in `MAINTAINERS.md`.

This is a configuration WP plus a documentation substitution, plus optionally a small CI workflow file (`.github/workflows/mirror-sync.yml`) IF you select the workflow-driven sync mechanism (the alternative is the forge's built-in polling mirror).

## Decision deferred to you (with Russell)

**Russell selects the Nation-hosted forge at WP04 execution time.** Do NOT proceed past T012 without a confirmed forge selection.

Selection criteria (from `../spec.md` §"Decisions deferred to implementer"):

- Nation-controlled or OIATC-controlled host (not a SaaS subdomain even if cheaper).
- FOSS forge software (Gitea or Forgejo). Not a vendor-locked SaaS.
- HTTPS-accessible URL.
- Supports webhook-driven mirror push from GitHub Actions OR polling-based mirror pull from the forge.
- Held under a domain a Nation procurement officer would recognise as Nation-controlled (e.g. `git.<oiatc-or-nation-domain>.ca` rather than `gitea.com/<org>` or `codeberg.org/<org>`).

Preference order (from `../spec.md` C-005): **preserve OCAP audit lineage > minimise vendor lock-in > don't break codified policy gates.** Concretely for this WP: FOSS forge software (minimises vendor lock-in); the mirror is read-only in steady state (preserves OCAP audit lineage by keeping a single canonical write surface on GitHub until the recovery procedure activates).

## THE pattern to mirror (read these before editing)

- `../plan.md` §4 — full operational + documentation steps.
- `../spec.md` FR-006, FR-009, NFR-005 — read-only-in-steady-state and recovery procedure requirements.
- `MAINTAINERS.md` "Nation-hosted mirror" section (from WP01) — the documentation lives here.

## Subtasks

### T012 — Confirm forge selection with Russell

If Russell has not yet named the forge, surface this as a blocker and pause the WP. Do NOT improvise a host.

Once confirmed, record (locally — you will write to MAINTAINERS.md in T015):

- Mirror URL (e.g. `https://git.example-nation.ca/waaseyaa/framework`).
- Forge software and version (e.g. `Forgejo 8.0` or `Gitea 1.22`).
- Sync mechanism chosen (workflow-driven push vs forge polling pull).

### T013 — Create mirror repository on the forge (operational)

This step requires forge admin credentials. If Russell is driving this step, you wait for confirmation. If Russell delegates and you have credentials:

1. On the chosen forge, create the organisation `waaseyaa` (or equivalent namespace).
2. Create a repository `framework` (or equivalent) configured as a read-only mirror of `github.com/waaseyaa/framework`.
3. Add the Packagist trustee account (from WP03) as a co-admin on the mirror so the recovery procedure can complete without coordinating credentials.

### T014 — Configure sync mechanism (operational)

Two options:

**Option A (workflow-driven push, preferred):** Create `.github/workflows/mirror-sync.yml` in the framework repo with a `push` trigger on `main` and a job that pushes all refs to the mirror via a deploy key held in the GitHub Actions secret store (secret name: `MIRROR_DEPLOY_KEY`). Have Russell add the deploy key to the GitHub Actions secret store before this commits. The workflow is short (one job, one step using `git push --mirror`).

**Option B (forge polling pull, simpler):** Use the forge's built-in "mirror from external repo" feature. Configure it to poll `github.com/waaseyaa/framework` at minimum nightly cadence. No file in the framework repo needed — the configuration lives entirely on the forge side.

Run the chosen mechanism through one initial sync and confirm: `git ls-remote <mirror-url> refs/heads/main` returns the same SHA as `git ls-remote https://github.com/waaseyaa/framework refs/heads/main`.

### T015 — Substitute marker tokens in MAINTAINERS.md

In `/MAINTAINERS.md`:

1. Replace both occurrences of `<<NATION_HOSTED_MIRROR_URL>>` with the chosen mirror URL (the literal URL — backticks are already in the surrounding text in the table cell; the recovery-procedure step 1 has the URL as a `git remote set-url` argument so include just the URL there too).
2. In the "Nation-hosted mirror" table, replace the placeholder text `(recorded at mirror setup by WP04 of `succession-framework-tier1-publishing-01KSEFV6`; MUST be FOSS — Gitea or Forgejo)` in the "Forge software" cell with the actual forge software and version (e.g. `Forgejo 8.0`).
3. Confirm the "Mirror forge selection rationale" paragraph reflects the criteria actually applied. If your selection deviated from the criteria documented (you should not deviate; if you did, that's a blocker — surface it), update the paragraph to record the actual rationale. If selection matched criteria, no edit needed.

Run:

- `grep -c "<<NATION_HOSTED_MIRROR_URL>>" MAINTAINERS.md` → `0`
- `grep -c "<the mirror URL>" MAINTAINERS.md` → at least `2`

### T016 — Verify and commit

Run the verification checks:

- `grep -c "<<NATION_HOSTED_MIRROR_URL>>" MAINTAINERS.md` → `0`
- `grep -c "recorded at mirror setup by WP04" MAINTAINERS.md` → `0`
- `grep -cE "TBD|TODO|_placeholder_|<placeholder>|<<[A-Z_]+>>" MAINTAINERS.md SUCCESSION.md` → `0` (final mission-wide check; the two new files now contain NO marker tokens of any kind)
- `git ls-remote <chosen-mirror-url> refs/heads/main` returns the same SHA as `git ls-remote https://github.com/waaseyaa/framework refs/heads/main`.
- Manual check: from an unauthenticated machine, `git clone <mirror-url>` succeeds; from a developer's clone, `git push <mirror-url> main` is rejected (mirror is read-only in steady state).

If all checks pass, stage and commit:

```
# If Option A (workflow file) was used:
git add MAINTAINERS.md .github/workflows/mirror-sync.yml
# If Option B (forge polling) was used:
git add MAINTAINERS.md

git commit -m "docs(governance): record Nation-hosted mirror in MAINTAINERS.md (succession-framework-tier1-publishing-01KSEFV6 WP04)"
```

NO other files staged. Forge-side configuration is external to the repo and verified by the manual check, not by a commit.

## Verification gate (in lane worktree)

All T016 checks pass. The mirror serves the same `main` ref as GitHub. The mirror is read-only from the mirror side in the steady state. No marker tokens remain in MAINTAINERS.md or SUCCESSION.md.

## Commit + handoff

After T016, move WP to for_review:

```
cd /home/fsd42/dev/waaseyaa
spec-kitty agent tasks mark-status T012 T013 T014 T015 T016 --status done --mission succession-framework-tier1-publishing-01KSEFV6
spec-kitty agent tasks move-task WP04 --to for_review --mission succession-framework-tier1-publishing-01KSEFV6 --note "Mirror <url> operational; sync mechanism <A|B>; MAINTAINERS.md substituted; spot-check confirmed; mirror is read-only in steady state."
```

After approval and merge, this WP closes the mission.

## Report back with

- The chosen mirror URL, forge software + version, and sync mechanism (A or B).
- Confirmation of the matching `main` SHA between GitHub and the mirror.
- Confirmation that an unauthorised push to the mirror is rejected.
- Whether the "Mirror forge selection rationale" paragraph required edits to reflect the actual rationale.

## Activity Log

(populated by the implementing agent as work progresses)
- 2026-05-25T04:57:48Z – unknown – Opus review: markdown-only mission, verbatim from plan.md §1/§2. WP03/WP04 deferral markers documented per spec.
