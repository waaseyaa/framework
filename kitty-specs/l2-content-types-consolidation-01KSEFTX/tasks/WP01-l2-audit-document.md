---
work_package_id: "WP01"
title: "L2 audit document — per-package classification + rationale"
dependencies: []
requirement_refs:
  - "FR-001"
  - "NFR-003"
  - "C-002"
  - "C-003"
planning_base_branch: "main"
merge_target_branch: "main"
branch_strategy: "Planning artifacts for this mission were generated on main. WP01 produces the audit; WP02 and WP03 reference it."
subtasks:
  - "T001"
  - "T002"
  - "T003"
phase: "Phase 1 - Audit"
assignee: ""
agent: ""
shell_pid: ""
authoritative_surface: "docs/audits/2026-05-l2-content-types-audit.md"
execution_mode: "documentation"
owned_files:
  - "docs/audits/2026-05-l2-content-types-audit.md"
history: []
---

# WP01 — L2 audit document

**Mission:** `l2-content-types-consolidation-01KSEFTX`
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)

## Subtasks

**T001 — Enumerate L2 packages from the canonical layer table (FR-001)**

Read CLAUDE.md's layer-architecture table. List every package in the L2 (Content Types) row. Cross-check against `packages/` directory contents — flag any inconsistency in the audit's "Notes" section.

If the orchestration table treats `attachment` and `structured-import` as part of the work-surface group rather than pure L2, follow CLAUDE.md's framing (these go in the audit if the L2 row lists them; excluded with a one-line note otherwise).

**T002 — Per-package fact-finding (FR-001, NFR-003)**

For each L2 package, gather:

- **Source state:** read `packages/<name>/composer.json` for description + version; read `README.md` for layer attribution + class summary; list entity types via `rg -l 'new EntityType\(' packages/<name>/src`; list `@api`-tagged public-extension-point classes via `rg -l '@api' packages/<name>/src`; count tests via `find packages/<name>/tests -name '*Test.php' | wc -l`.
- **Consumer surfaces:** `rg -l "waaseyaa/<name>|use Waaseyaa\\<PascalCaseName>" packages/admin/app packages/api/src/Controller packages/foundation/src` to find consumers. Note specific page paths + controllers.
- **Recent activity:** `git log --oneline --since='3 months ago' -- packages/<name>/ | wc -l` (informational; not a classification driver).
- **Anokii productivity-surface mapping:** which Anokii surface does this package power? Read the parallel anokii-distribution-scaffold work if filed; otherwise infer from the package's domain (e.g., `node` → Anokii Wiki; `media` → Anokii Files; etc.). If no clear surface, "framework substrate (no distribution-specific surface)".

**T003 — Classify + write rationale (FR-001, C-003)**

For each package, pick one classification:

- **production-ready** — actively extended (recent commits), has consumers, has tests (>= a handful), has a clear spec or README.
- **alpha — needs hardening** — exists, has structure, but: no consumer in admin SPA, no recent activity, sparse tests, OR known dead-code-baseline entries.
- **dead — propose removal** — exists in tree but no consumer, no tests of substance, no clear future role.

Write a one-paragraph rationale per package. The rationale MUST cite evidence — "no admin SPA consumer per `rg packages/admin`", "27 dead-code-baseline entries per `phpstan-dead-code-baseline.neon`", etc.

A classification without rationale fails C-003. Reviewer cross-checks at least three classifications against the actual package state.

Then write the audit document per the structure in `../plan.md` §1. Save at `docs/audits/2026-05-l2-content-types-audit.md`.

## Verification gate

1. The audit document exists at the canonical path.
2. Every L2 package per the layer table is covered (FR-001).
3. Every classification has a rationale paragraph (C-003).
4. The "Sources consulted" section lists the file paths / commands used (NFR-003).
5. The Summary table at the bottom matches the per-package classifications.

## Commit + handoff

- Commit (footer `Mission: l2-content-types-consolidation-01KSEFTX`):
  - `docs(audits): L2 content-types audit (2026-05)`
- Then:
  ```
  spec-kitty agent tasks mark-status T001 T002 T003 --status done --mission l2-content-types-consolidation-01KSEFTX
  spec-kitty agent tasks move-task WP01 --to for_review --mission l2-content-types-consolidation-01KSEFTX --note "Audit done; <X> production-ready, <Y> needs-hardening, <Z> dead-proposed. WP02 (follow-up missions) and WP03 (messaging→L3) unblocked."
  ```

## Report back with
1. Commit SHA.
2. The Summary table from the audit (paste).
3. The counts per classification (production-ready / alpha / dead).
4. Three classifications + rationales the reviewer should spot-check first.

## Activity Log
- 2026-05-25T06:19:49Z – unknown – approved
