---
work_package_id: WP02
title: GraphQL README banner + manifest demotion from full to suggest
dependencies: [WP01]
requirement_refs:
- FR-004
- FR-005
- FR-006
- FR-007
- NFR-001
- NFR-002
- C-001
- C-002
- C-003
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this mission were generated on main. WP02 depends on WP01 (the JSON:API primary declaration is the canonical reference the GraphQL banner points at). May run in parallel with WP03 after WP01 lands.
subtasks:
- T003
- T004
- T005
phase: Phase 2 - Manifest + README
assignee: ''
agent: ''
shell_pid: ''
history: []
authoritative_surface: packages/graphql/README.md
execution_mode: documentation
mission_id: 01KSEFTVFWVRP1AJ1GH2AJDB9P
owned_files:
- packages/graphql/README.md
- packages/full/composer.json
- packages/graphql/composer.json
- composer.lock
wp_code: WP02
---

# WP02 — GraphQL README banner + manifest demotion from full to suggest

**Mission:** `api-surface-consolidation-jsonapi-primary-01KSEFTV`
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)

## Subtasks

**T003 — GraphQL README banner + Status section (FR-004, FR-005, C-003)**

Read `packages/graphql/README.md` first. After the H1 (`# waaseyaa/graphql`) and one blank line, before any existing subhead, insert the verbatim banner blockquote from `../plan.md` §1. After the existing class summary, append the verbatim `## Status` section from `../plan.md` §1.

Same exact-match discipline as the Inertia mission: do not rephrase, do not localise, do not improve. C-003 makes the text canonical.

**T004 — full + graphql composer.json manifest edits (FR-006, C-003)**

`packages/full/composer.json`:
1. Remove the `"waaseyaa/graphql": "self.version",` (or current constraint) line from `require`.
2. Add the verbatim suggest entry from `../plan.md` §2 to the `suggest` block. Maintain alphabetical ordering (`graphql` before `inertia` if the Inertia mission's entry is already present).

`packages/graphql/composer.json`:
- Read first. If `description` already conveys optional/experimental status, **no edit**. Otherwise, update to: `"description": "GraphQL endpoint + schema introspection for Waaseyaa — optional/experimental L6 surface; see README for the primary JSON:API framing."`.
- **Do not touch `require`, `require-dev`, `autoload`, or `extra`.**

**T005 — composer.lock refresh + gate verification (FR-007, NFR-002)**

```
composer update --lock waaseyaa/graphql waaseyaa/full
```

Inspect `composer.lock` diff:
- `waaseyaa/graphql` either removed from `packages` (if nothing else requires it) OR retained but no longer transitive via `waaseyaa/full`.
- No other package version should change.

Then run gates:

```
composer cs-check
composer phpstan
bin/check-composer-policy
bin/check-package-layers
bin/check-dead-code
bin/check-getquery-bindings
```

All green.

## Verification gate

1. `git diff packages/graphql/README.md` — banner + Status section added.
2. `git diff packages/graphql/src packages/graphql/tests` — empty (NFR-001 + C-001).
3. `git diff packages/full/composer.json` — one removal from `require`, one addition to `suggest`.
4. `git diff packages/graphql/composer.json` — at most a description edit.
5. `git diff composer.lock` — regenerated (FR-007).
6. All codified gates green (NFR-002).
7. The banner + suggest description text match `../plan.md` §1 and §2 character-for-character (C-003).

## Commit + handoff

- Commits (footer `Mission: api-surface-consolidation-jsonapi-primary-01KSEFTV`):
  - `docs(graphql): README banner + Status section per API-surface consolidation`
  - `chore(full): demote waaseyaa/graphql to suggest`
  - `chore(deps): regenerate composer.lock after graphql demotion` (or fold)
- Then:
  ```
  spec-kitty agent tasks mark-status T003 T004 T005 --status done --mission api-surface-consolidation-jsonapi-primary-01KSEFTV
  spec-kitty agent tasks move-task WP02 --to for_review --mission api-surface-consolidation-jsonapi-primary-01KSEFTV --note "GraphQL README banner + manifest demotion landed; lock refreshed; gates green."
  ```

## Report back with
1. Commit SHA(s).
2. The new `packages/full/composer.json` `require` block (confirms `waaseyaa/graphql` absent).
3. The new `packages/full/composer.json` `suggest` block (confirms verbatim description).
4. `git diff --stat composer.lock` line count.
5. Output of `bin/check-composer-policy` and `bin/check-package-layers`.

## Activity Log

