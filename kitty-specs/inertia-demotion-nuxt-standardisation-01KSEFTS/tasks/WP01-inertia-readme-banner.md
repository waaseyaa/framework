---
work_package_id: "WP01"
title: "Inertia README banner + Status section (source-of-truth edit)"
dependencies: []
requirement_refs:
  - "FR-001"
  - "FR-002"
  - "NFR-001"
  - "C-001"
  - "C-003"
planning_base_branch: "main"
merge_target_branch: "main"
branch_strategy: "Planning artifacts for this mission were generated on main. The README banner lands first as the source-of-truth signal; WP02 and WP03 reference it."
subtasks:
  - "T001"
  - "T002"
phase: "Phase 1 - Source-of-truth banner"
assignee: "claude"
agent: ""
shell_pid: ""
authoritative_surface: "packages/inertia/README.md"
execution_mode: "documentation"
owned_files:
  - "packages/inertia/README.md"
history: []
---

# WP01 — Inertia README banner + Status section

**Mission:** `inertia-demotion-nuxt-standardisation-01KSEFTS`
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)

## Why this WP lands first

The README is the canonical reader signal for "what is this package?" WP02 (composer manifest) and WP03 (spec sweep) both point at this README as the explanation for the demotion. The README banner has to exist before those references make sense.

## Subtasks

**T001 — Insert the canonical banner blockquote (FR-001, C-003)**

Read `packages/inertia/README.md`. After the H1 (`# waaseyaa/inertia`) and one blank line, before the existing subhead (`**Layer 6 — Interfaces**`), insert the verbatim block from `../plan.md` §1 (the "Alternative protocol — not the primary workspace UI" blockquote). Preserve one blank line above and below the inserted block.

**The text is exact-match.** Do not rephrase, do not localise, do not "improve." C-003 makes this the canonical constitutional signal; reviewer diffs it character-for-character against `../plan.md` §1.

**T002 — Append the Status section (FR-002)**

After the existing class summary paragraph (the line listing `Inertia`, `InertiaResponse`, etc.) and one blank line, append the `## Status` section verbatim from `../plan.md` §1 (the three bullet points: Stability / Bundle membership / Decision provenance).

If the README ends partway through (e.g., already has a `## Status` or similar — read first), reconcile the existing content with the canonical text. Replace the existing section if its content conflicts; preserve it if it adds non-conflicting context.

## Verification gate

1. `git diff packages/inertia/README.md` — confirm only the banner blockquote + the Status section are added; no other lines changed.
2. `git diff packages/inertia/src packages/inertia/tests packages/inertia/composer.json` — empty (NFR-001 + C-001).
3. The banner text matches `../plan.md` §1 character-for-character (C-003). Use `diff` or `git diff` against the plan to verify.

## Commit + handoff

- Commit (footer `Mission: inertia-demotion-nuxt-standardisation-01KSEFTS`):
  - `docs(inertia): README banner + Status section per DIR-007`
- Then:
  ```
  spec-kitty agent tasks mark-status T001 T002 --status done --mission inertia-demotion-nuxt-standardisation-01KSEFTS
  spec-kitty agent tasks move-task WP01 --to for_review --mission inertia-demotion-nuxt-standardisation-01KSEFTS --note "Banner + Status section landed; WP02 (manifest) and WP03 (docs sweep) unblocked."
  ```

## Report back with
1. Commit SHA.
2. `git diff packages/inertia/src packages/inertia/tests packages/inertia/composer.json` (must be empty).
3. The exact rendered banner text from the post-merge README (paste).

## Activity Log
- 2026-05-25T06:11:11Z – unknown – Moved to in_progress
- 2026-05-25T06:12:08Z – unknown – Banner + Status section added to packages/inertia/README.md. No src/tests/composer.json changes. Commit 76ea360a5.
- 2026-05-25T06:16:18Z – unknown – Opus review: docs-only; subagent worked on main worktree (lane discipline bypass acknowledged but acceptable for documentation execution_mode); DIR-007 banner + composer suggest block + SPA-bet section all in place; gates clean
