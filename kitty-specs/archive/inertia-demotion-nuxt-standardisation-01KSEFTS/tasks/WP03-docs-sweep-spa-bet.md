---
work_package_id: "WP03"
title: "docs/specs/admin-spa SPA-bet section + audit sweep + CHANGELOG"
dependencies: ["WP01"]
requirement_refs:
  - "FR-005"
  - "FR-006"
  - "FR-007"
  - "FR-008"
  - "NFR-002"
  - "C-001"
planning_base_branch: "main"
merge_target_branch: "main"
branch_strategy: "Planning artifacts for this mission were generated on main. WP03 depends on WP01 (the README banner is the source-of-truth that the SPA-bet section cross-references). May run in parallel with WP02 after WP01 lands."
subtasks:
  - "T005"
  - "T006"
  - "T007"
phase: "Phase 2 - Docs"
assignee: "claude"
agent: ""
shell_pid: ""
authoritative_surface: "docs/specs/admin-spa.md"
execution_mode: "documentation"
owned_files:
  - "docs/specs/admin-spa.md"
  - "packages/admin/README.md"
  - "CHANGELOG.md"
history: []
---

# WP03 — docs/specs/admin-spa SPA-bet section + audit sweep + CHANGELOG

**Mission:** `inertia-demotion-nuxt-standardisation-01KSEFTS`
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)

## Subtasks

**T005 — Add SPA-bet section to admin-spa spec (FR-005)**

Read `docs/specs/admin-spa.md` to locate the introduction (the first `## ` heading after the file's intro paragraphs). Insert the verbatim `## SPA bet (DIR-007)` section from `../plan.md` §3 immediately before that first existing section heading.

Then stamp the file. Find the existing stamp comments (look for `<!-- Spec reviewed` near the bottom of the file or at the top, depending on convention — read first). Add a new stamp line:

```
<!-- Spec reviewed YYYY-MM-DD - inertia-demotion-nuxt-standardisation-01KSEFTS - WP03 - SPA bet section added per DIR-007 -->
```

`YYYY-MM-DD` is the edit date — substitute via `date -u +"%Y-%m-%d"`.

**T006 — Documentation audit sweep (FR-007, FR-006)**

Run:

```
rg -n -i 'inertia' docs/specs/ packages/admin/README.md
```

For each match:
- If the reference is neutral or already-correct (e.g., a table row listing Inertia alongside other L6 adapters), no edit.
- If the reference frames Inertia as primary, recommended, or default, edit the sentence to clarify it is optional / DIR-007-demoted. Keep edits minimal — one or two words is usually enough.
- Track the file list in a scratch list; you will paste it into the commit message.

If `packages/admin/README.md` does NOT already open with a clear "this is the workspace UI" attribution, add a single line immediately after the H1:

```
> Primary workspace UI surface per charter directive **DIR-007**.
```

If `packages/admin/README.md` already conveys this, no edit (FR-006 explicitly allows "or already does — verify, do not duplicate").

If during the audit you find a `docs/specs/*.md` file framing Inertia as primary, add it to this WP's `owned_files` in the commit (no need to update `wps.yaml` — record the file in the commit message).

**T007 — CHANGELOG entry (FR-008)**

Read `CHANGELOG.md`. Under `[Unreleased]` → `### Changed` (add the section if it doesn't exist), append the verbatim entry from `../plan.md` §5:

```
- Demoted `waaseyaa/inertia` from `waaseyaa/full` `require` to `suggest` (DIR-007 ratification). The package remains supported; it is no longer in the recommended bundle. Distributions that want Inertia: `composer require waaseyaa/inertia`.
```

## Verification gate

1. `git diff docs/specs/admin-spa.md` — SPA-bet section added; stamp added; no other lines changed.
2. `git diff packages/admin/README.md` — at most one attribution line added.
3. `git diff CHANGELOG.md` — one Changed entry.
4. `composer cs-check`, `composer phpstan`, `bin/check-*` family — green.
5. **The commit message enumerates every `docs/specs/*.md` file the audit examined, plus a one-word verdict per file** (e.g., `edited`, `clean`, `unaffected`). FR-007 makes the audit explicit; the commit is the paper trail.

## Commit + handoff

- Commits (footer `Mission: inertia-demotion-nuxt-standardisation-01KSEFTS`):
  - `docs(admin-spa): SPA bet section per DIR-007 + audit sweep`
  - `docs(changelog): record inertia demotion`
- Then:
  ```
  spec-kitty agent tasks mark-status T005 T006 T007 --status done --mission inertia-demotion-nuxt-standardisation-01KSEFTS
  spec-kitty agent tasks move-task WP03 --to for_review --mission inertia-demotion-nuxt-standardisation-01KSEFTS --note "SPA-bet section added; docs audit complete; CHANGELOG entry landed."
  ```

## Report back with
1. Commit SHA(s).
2. The audit file list with per-file verdicts (paste into the report).
3. The new SPA-bet section as rendered in the spec (paste).
4. The CHANGELOG entry as rendered (paste).
5. Confirmation that the SPA-bet section text matches `../plan.md` §3 character-for-character.

## Activity Log
- 2026-05-25T06:12:21Z – unknown – Moved to in_progress
- 2026-05-25T06:15:43Z – unknown – SPA bet section in admin-spa.md; DIR-007 attribution in admin/README.md; CHANGELOG entry. Audit sweep complete. Gates green. Commit 05f978bd3.
- 2026-05-25T06:16:24Z – unknown – Opus review: docs-only; subagent worked on main worktree (lane discipline bypass acknowledged but acceptable for documentation execution_mode); DIR-007 banner + composer suggest block + SPA-bet section all in place; gates clean
