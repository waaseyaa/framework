---
work_package_id: WP01
title: docs/specs/jsonapi — primary-surface declaration + parity-matrix scaffold
dependencies: []
requirement_refs:
- FR-001
- FR-002
- FR-003
- NFR-002
- NFR-003
- C-003
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this mission were generated on main. WP01 lands first — the JSON:API spec declaration is the source-of-truth that WP02's banner and WP03's audit both reference.
subtasks:
- T001
- T002
phase: Phase 1 - Spec declaration
assignee: ''
agent: ''
shell_pid: ''
history: []
authoritative_surface: docs/specs/jsonapi.md
execution_mode: documentation
mission_id: 01KSEFTVFWVRP1AJ1GH2AJDB9P
owned_files:
- docs/specs/jsonapi.md
wp_code: WP01
---

# WP01 — docs/specs/jsonapi — primary-surface declaration + parity-matrix scaffold

**Mission:** `api-surface-consolidation-jsonapi-primary-01KSEFTV`
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)

## Subtasks

**T001 — Insert the Status (primary API surface) section (FR-001, C-003)**

Read `docs/specs/jsonapi.md` first to locate the introduction (the file's intro paragraph(s) before the first existing `## ` heading) and any existing stamp comments.

Insert the verbatim `## Status (primary API surface)` block from `../plan.md` §3 between the intro paragraph and the first existing `## ` heading. Preserve one blank line above and below.

Replace `<edit-date>` in the inserted text with `date -u +"%Y-%m-%d"`.

**T002 — Add the parity-matrix scaffold + stamp (FR-002, FR-003)**

Near the bottom of the spec (before any footer-stamp area), insert the parity-matrix scaffold from `../plan.md` §3:

- The `## Feature parity matrix vs current GraphQL exposure` heading + the explanation paragraph + the Markdown table with the five required column headers.
- The table body contains a single placeholder row: `| <populated by WP03> | | | | |`.

Then stamp:

```
<!-- Spec reviewed YYYY-MM-DD - api-surface-consolidation-jsonapi-primary-01KSEFTV - WP01 - JSON:API primary declaration + parity matrix -->
```

## Verification gate

1. `git diff docs/specs/jsonapi.md` — Status section + matrix scaffold + stamp added; no other lines changed.
2. The Status text matches `../plan.md` §3 character-for-character (C-003).
3. The matrix has all five required column headers (Entity / Operation, JSON:API surface, GraphQL surface, Gap (if any), Follow-up mission) in that exact order.
4. All codified gates green (NFR-002).

## Commit + handoff

- Commit (footer `Mission: api-surface-consolidation-jsonapi-primary-01KSEFTV`):
  - `docs(jsonapi): declare JSON:API as primary API surface + parity-matrix scaffold`
- Then:
  ```
  spec-kitty agent tasks mark-status T001 T002 --status done --mission api-surface-consolidation-jsonapi-primary-01KSEFTV
  spec-kitty agent tasks move-task WP01 --to for_review --mission api-surface-consolidation-jsonapi-primary-01KSEFTV --note "JSON:API primary declared; matrix scaffold added; WP02 (manifest) + WP03 (audit) unblocked."
  ```

## Report back with
1. Commit SHA.
2. Paste the inserted Status section (post-edit).
3. Paste the inserted matrix scaffold + the stamp.

## Activity Log

