---
work_package_id: "WP01"
title: "Publish MAINTAINERS.md and README.md governance pointer"
dependencies: []
planning_base_branch: "main"
merge_target_branch: "main"
branch_strategy: "Planning artifacts were generated on main; completed changes must merge back into main."
subtasks:
  - "T001"
  - "T002"
  - "T003"
  - "T004"
phase: "Tier 1 — Practical pre-conditions"
assignee: ""
agent: ""
shell_pid: ""
history:
  - timestamp: "2026-05-24T00:00:00Z"
    agent: "system"
    action: "Prompt generated via /spec-kitty.tasks"
---

# Work Package Prompt: WP01 — Publish MAINTAINERS.md and README pointer

## CRITICAL — work in the lane worktree

```
cd /home/fsd42/dev/waaseyaa/.worktrees/succession-framework-tier1-publishing-01KSEFV6-lane-a
```

(Exact path is printed by `spec-kitty agent action implement WP01`.) This WP is documentation-only — no `composer install` or `npm install` needed.

## What you are doing

Publishing the framework's `MAINTAINERS.md` at the repo root. This is the procurement-legible answer to "what happens if Russell stops". It enumerates Tier 0 trust substrate with file pointers, lists current maintainers (primary + Packagist trustee), documents decision authority, and lays the escalation procedure.

You are ALSO adding a one-line governance pointer to `README.md` so first-time repo readers reach the maintenance posture without searching.

The file content is fully specified in `../plan.md` §1 — apply it verbatim. Two marker tokens (`<<TRUSTEE_PACKAGIST_ACCOUNT>>` and `<<NATION_HOSTED_MIRROR_URL>>`) are left in place at WP01 close; WP03 and WP04 substitute them.

## THE pattern to mirror (read these before editing)

- `.kittify/charter/charter.md` — match the authoritative tone: MUST / MUST NOT where binding, concrete consequences, no marketing prose, no hedging in normative clauses.
- `../plan.md` §1 — the full file content. Apply verbatim except for the `<as of YYYY-MM-DD>` date stamp which you substitute at edit time.
- `../plan.md` §5 — the README.md one-line insertion.

## Subtasks

### T001 — Substitute date stamp and write `/MAINTAINERS.md`

Get the current UTC date: `date -u +"%Y-%m-%d"`. In the `../plan.md` §1 content, replace the single occurrence of `<as of YYYY-MM-DD>` (inside the Tier 0 substrate inventory section, on the italicised line `*(as of <as of YYYY-MM-DD>)*`) with the actual date.

Leave `<<TRUSTEE_PACKAGIST_ACCOUNT>>` (two occurrences) and `<<NATION_HOSTED_MIRROR_URL>>` (two occurrences) IN PLACE — WP03 and WP04 substitute these. Do NOT pre-fill them.

Write the result to `/MAINTAINERS.md` at the repo root.

### T002 — Verify Tier 0 substrate inventory matches repo reality

For each entry in the "CI gates", "Audit tools", and "Spec freshness" subsections, confirm the named file exists in the repo:

```
ls bin/check-composer-policy bin/check-package-layers bin/check-dead-code \
   bin/check-getquery-bindings bin/check-ingestion-defaults bin/check-no-secrets \
   bin/check-openapi bin/check-admin-coercion-patterns bin/check-monorepo-release-shape \
   bin/check-phpstan bin/check-release-tag-parity bin/check-symfony-imports \
   bin/check-external-consumers bin/audit-composer-deps bin/audit-require-dev-layers \
   tools/drift-detector.sh
```

If any file is missing, STOP and surface the discrepancy — do not silently delete the entry from MAINTAINERS.md. The plan-time inventory was derived from `ls /home/fsd42/dev/waaseyaa/bin/check-* bin/audit-* tools/drift-detector.sh`; if reality has drifted, the discrepancy needs author attention before publication.

### T003 — Insert README.md governance pointer

Read `/README.md`. Locate the first prose section after the badge/header block. Immediately before that section, insert (with one blank line above and one below) exactly:

```markdown
> Governance: see [MAINTAINERS.md](MAINTAINERS.md) for the current maintainer roster and [SUCCESSION.md](SUCCESSION.md) for the framework's continuity plan across Tiers 0–4.
```

If the documented anchor (immediately after badge/header block, before first prose section) cannot be located unambiguously, STOP and surface the discrepancy — do not improvise an alternative insertion point.

### T004 — Verify and commit

Run the verification checks:

- `test -f /home/fsd42/dev/waaseyaa/MAINTAINERS.md` → exit 0
- `grep -c "Tier 0 substrate inventory" MAINTAINERS.md` → `1`
- `grep -c "Current maintainers" MAINTAINERS.md` → `1`
- `grep -c "Decision authority" MAINTAINERS.md` → `1`
- `grep -c "Nation-hosted mirror" MAINTAINERS.md` → `1`
- `grep -c "DIR-006" MAINTAINERS.md` → at least `1`
- `grep -c "SUCCESSION.md" MAINTAINERS.md` → at least `1`
- `grep -c "MAINTAINERS.md" README.md` → at least `1`
- `grep -c "SUCCESSION.md" README.md` → at least `1`
- `grep -c "<as of YYYY-MM-DD>" MAINTAINERS.md` → `0` (the placeholder was substituted)
- `grep -cE "TBD|TODO|_placeholder_|<placeholder>" MAINTAINERS.md` → `0`

Marker tokens that ARE expected at WP01 close (substituted by WP03/WP04):

- `grep -c "<<TRUSTEE_PACKAGIST_ACCOUNT>>" MAINTAINERS.md` → `2` (expected — WP03 substitutes)
- `grep -c "<<NATION_HOSTED_MIRROR_URL>>" MAINTAINERS.md` → `2` (expected — WP04 substitutes)

If all checks pass, commit:

```
git add MAINTAINERS.md README.md
git commit -m "docs(governance): publish MAINTAINERS.md (succession-framework-tier1-publishing-01KSEFV6 WP01)"
```

NO other files staged.

## Verification gate (in lane worktree)

All T004 checks must pass. The two `<<...>>` marker tokens MUST remain unsubstituted at WP01 close.

## Commit + handoff

After T004, move WP to for_review:

```
cd /home/fsd42/dev/waaseyaa
spec-kitty agent tasks mark-status T001 T002 T003 T004 --status done --mission succession-framework-tier1-publishing-01KSEFV6
spec-kitty agent tasks move-task WP01 --to for_review --mission succession-framework-tier1-publishing-01KSEFV6 --note "MAINTAINERS.md and README.md pointer published; marker tokens left for WP03/WP04 substitution; all verifications pass."
```

After approval and merge, WP03 (Packagist trustee) is unblocked.

## Report back with

- Whether T002 (substrate inventory reality check) found any missing files (if so, list them).
- The substituted `(as of YYYY-MM-DD)` date.
- Confirmation that both `<<...>>` marker tokens remain unsubstituted (with grep counts).
- Confirmation that the README.md insertion point was unambiguous.

## Activity Log

(populated by the implementing agent as work progresses)
- 2026-05-25T04:57:38Z – unknown – Opus review: markdown-only mission, verbatim from plan.md §1/§2. WP03/WP04 deferral markers documented per spec.
