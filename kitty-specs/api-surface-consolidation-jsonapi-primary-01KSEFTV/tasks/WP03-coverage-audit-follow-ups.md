---
work_package_id: WP03
title: Coverage audit + parity matrix population + CHANGELOG + follow-up missions
dependencies: [WP01]
requirement_refs:
- FR-008
- FR-009
- NFR-002
- C-004
- C-005
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this mission were generated on main. WP03 depends on WP01 (matrix scaffold is what WP03 populates). May run in parallel with WP02 after WP01 lands. Per-gap follow-up mission scaffolds are filed by this WP via `spec-kitty specify` and committed alongside this WP's matrix population.
subtasks:
- T006
- T007
- T008
phase: Phase 2 - Audit + follow-ups
assignee: ''
agent: ''
shell_pid: ''
history: []
authoritative_surface: docs/specs/jsonapi.md
execution_mode: documentation
mission_id: 01KSEFTVFWVRP1AJ1GH2AJDB9P
owned_files:
- docs/specs/jsonapi.md
- CHANGELOG.md
wp_code: WP03
---

# WP03 — Coverage audit + parity matrix population + CHANGELOG + follow-up missions

**Mission:** `api-surface-consolidation-jsonapi-primary-01KSEFTV`
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)

## Subtasks

**T006 — Audit GraphQL exposure (C-005)**

Follow the method in `../plan.md` §4 exactly:

1. **Enumerate GraphQL exposure.** Read every schema-defining file in `packages/graphql/src/` (likely `*Schema*.php`, `*Type*.php`, `*Resolver*.php`, `*Query*.php`, `*Mutation*.php`; if schemas are in `.graphql` files, read those). List every type, top-level query, top-level mutation.
2. **Enumerate JSON:API exposure.** Read every `*Controller.php` in `packages/api/src/Controller/`. Cross-reference `packages/foundation/src/Kernel/BuiltinRouteRegistrar.php` for confirmed route mappings.
3. **Build the cross-reference table.** For each GraphQL exposure, find the JSON:API equivalent (or note its absence).

The audit's reproducibility is C-005. Reviewer will run `rg -n -i 'type|query|mutation|@field' packages/graphql/src/` (or equivalent for the schema format) and cross-check the matrix against the result. Every match in the schema MUST appear as a matrix row.

**T007 — Populate the parity matrix + file follow-up missions (FR-008, C-004)**

In `docs/specs/jsonapi.md`, replace the `| <populated by WP03> | | | | |` placeholder row with one row per entity / operation enumerated in T006. Use one of three statuses:

- **`parity`** — both surfaces expose this with equivalent semantics. Leave "Gap (if any)" and "Follow-up mission" blank.
- **`JSON:API only`** — JSON:API exposes; GraphQL does not. Leave "Gap (if any)" and "Follow-up mission" blank.
- **`GAP`** — GraphQL exposes; JSON:API does not. Fill "Gap (if any)" with a one-line description. Fill "Follow-up mission" with a fresh mission slug.

**For each GAP row, file a follow-up mission scaffold:**

```
spec-kitty specify api-jsonapi-gap-<entity-or-operation>-<short-ULID>
```

The fresh mission's spec.md is a stub describing the gap (one paragraph: what GraphQL exposes, why JSON:API needs the equivalent, who uses it). That mission's WPs are scoped separately and live outside this mission. Record the slug in this matrix's "Follow-up mission" column.

After all GAP rows are filed, update the **Out-of-band** section of `../spec.md` to replace the placeholder list with the real list of follow-up missions (one slug + one-sentence summary per line). If the audit found zero gaps, replace the placeholder with: `No GAP rows in the parity matrix; no follow-up missions filed.`

**T008 — CHANGELOG entry (FR-009)**

Read `CHANGELOG.md`. Under `[Unreleased]` → `### Changed`, append the verbatim entry from `../plan.md` §5.

## Verification gate

1. The parity matrix has one row per GraphQL exposure (C-005). Reviewer cross-checks against `rg` on the schema.
2. Every GAP row has a non-empty "Follow-up mission" cell (C-004), and `ls kitty-specs/api-jsonapi-gap-*/spec.md` returns one path per slug.
3. `git diff packages/graphql/src packages/graphql/tests` — empty (C-001).
4. `git diff CHANGELOG.md` — one Changed entry.
5. `../spec.md` Out-of-band section is post-edited with real follow-up slugs (or the "zero gaps" sentence).
6. All codified gates green (NFR-002).

## Commit + handoff

- Commits (footer `Mission: api-surface-consolidation-jsonapi-primary-01KSEFTV`):
  - `docs(jsonapi): populate JSON:API ↔ GraphQL parity matrix`
  - `spec-kitty: file follow-up missions for JSON:API gaps` (if any GAP rows exist)
  - `docs(changelog): record JSON:API primary + GraphQL demotion`
- Then:
  ```
  spec-kitty agent tasks mark-status T006 T007 T008 --status done --mission api-surface-consolidation-jsonapi-primary-01KSEFTV
  spec-kitty agent tasks move-task WP03 --to for_review --mission api-surface-consolidation-jsonapi-primary-01KSEFTV --note "Parity matrix populated; <N> follow-up missions filed; CHANGELOG entry landed."
  ```

## Report back with
1. Commit SHA(s).
2. The full populated parity matrix as rendered in the spec (paste).
3. The list of follow-up mission slugs filed (or "zero gaps").
4. The CHANGELOG entry as rendered.
5. Output of `rg -c 'GAP' docs/specs/jsonapi.md` (must match the number of follow-up missions filed).

## Activity Log

