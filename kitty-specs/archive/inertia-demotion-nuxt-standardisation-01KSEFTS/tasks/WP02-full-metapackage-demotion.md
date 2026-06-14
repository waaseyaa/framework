---
work_package_id: "WP02"
title: "Demote waaseyaa/inertia from full require to suggest"
dependencies: ["WP01"]
requirement_refs:
  - "FR-003"
  - "FR-004"
  - "NFR-001"
  - "NFR-002"
  - "C-001"
  - "C-002"
  - "C-003"
planning_base_branch: "main"
merge_target_branch: "main"
branch_strategy: "Planning artifacts for this mission were generated on main. WP02 depends on WP01 (README banner is the canonical reference the suggest description points at). May run in parallel with WP03 after WP01 lands."
subtasks:
  - "T003"
  - "T004"
phase: "Phase 2 - Manifest"
assignee: "claude"
agent: ""
shell_pid: ""
authoritative_surface: "packages/full/composer.json"
execution_mode: "documentation"
owned_files:
  - "packages/full/composer.json"
  - "packages/inertia/composer.json"
  - "composer.lock"
history: []
---

# WP02 — Demote waaseyaa/inertia from full require to suggest

**Mission:** `inertia-demotion-nuxt-standardisation-01KSEFTS`
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)

## Subtasks

**T003 — Manifest edit (FR-003, C-003)**

Read `packages/full/composer.json` first. Then:

1. **`packages/full/composer.json` — remove from require:** Find the line `"waaseyaa/inertia": "self.version",` (or whatever the current constraint is) in the `require` block. Remove it. Preserve `sort-packages: true` alphabetical ordering of the surrounding lines.
2. **`packages/full/composer.json` — add to suggest:** Locate the `suggest` block. If absent, add one — placement: after `require-dev` if present, otherwise after `require`, before any `autoload` / `extra` blocks. The `suggest` block uses alphabetical-by-name ordering for entries.
   - Add the verbatim entry from `../plan.md` §2 (the `"waaseyaa/inertia": "Server-side Inertia.js v3 protocol adapter..."` line).
3. **`packages/inertia/composer.json` — description, conditionally:** Read it first. If the existing `description` already signals optional/experimental status, **leave it alone**. If it does not (the current state per inventory says it does not), update to: `"description": "Server-side Inertia.js v3 protocol adapter for Waaseyaa — optional/experimental L6 surface; see README for the DIR-007 framing."`.
   - **CRUCIAL:** do not touch `require`, `require-dev`, `autoload`, or `extra`. NFR-001 + C-001 forbid functional changes.

**T004 — Lock refresh + gate verification (FR-004, NFR-002)**

From the repo root:

```
composer update --lock waaseyaa/inertia waaseyaa/full
```

This refreshes `composer.lock` to reflect the dependency-graph change. Inspect the lock diff:

- `waaseyaa/inertia` should either be removed from the `packages` list (if no other package required it) OR retained but no longer as a transitive of `waaseyaa/full`.
- No other package's version should change. If the update touches anything else, ask Russell before continuing — it's a sign of stale lock that should be a separate mission.

Then run the codified gates:

```
composer cs-check
composer phpstan
bin/check-composer-policy
bin/check-package-layers
bin/check-dead-code
bin/check-getquery-bindings
```

All must remain green with no new findings.

## Verification gate

1. `git diff packages/full/composer.json` — one removal from `require`, one addition to `suggest`.
2. `git diff packages/inertia/composer.json` — at most a `description` edit. **Confirm no `require` / `require-dev` change** (NFR-001).
3. `git diff composer.lock` — present in this commit / PR (FR-004); contents reflect the demotion.
4. All codified gates green (NFR-002).
5. The suggest description text matches `../plan.md` §2 character-for-character (C-003).

## Commit + handoff

- Commits (footer `Mission: inertia-demotion-nuxt-standardisation-01KSEFTS`):
  - `chore(full): demote waaseyaa/inertia to suggest per DIR-007`
  - `chore(deps): regenerate composer.lock after inertia demotion` (or fold into the above if your project conventions prefer single-commit manifest changes)
- Then:
  ```
  spec-kitty agent tasks mark-status T003 T004 --status done --mission inertia-demotion-nuxt-standardisation-01KSEFTS
  spec-kitty agent tasks move-task WP02 --to for_review --mission inertia-demotion-nuxt-standardisation-01KSEFTS --note "Manifest demotion landed; composer.lock refreshed; gates green."
  ```

## Report back with
1. Commit SHA(s).
2. The new `packages/full/composer.json` `require` block (paste — confirms `waaseyaa/inertia` is absent).
3. The new `packages/full/composer.json` `suggest` block (paste — confirms verbatim description text).
4. `git diff --stat composer.lock` line count.
5. Output of `bin/check-composer-policy` and `bin/check-package-layers` (must be green).

## Activity Log
- 2026-05-25T06:12:18Z – unknown – Moved to in_progress
- 2026-05-25T06:15:43Z – unknown – suggest block added to packages/full/composer.json; inertia/composer.json description updated. Gates green. Commit 95dfda4a8.
- 2026-05-25T06:16:21Z – unknown – Opus review: docs-only; subagent worked on main worktree (lane discipline bypass acknowledged but acceptable for documentation execution_mode); DIR-007 banner + composer suggest block + SPA-bet section all in place; gates clean
