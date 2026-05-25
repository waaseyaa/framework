# Work Packages: genealogy-package-extraction-01KSEFTZ

_Generated from wps.yaml. Do not edit directly._

## Work Package WP01: composer description + spec banner + metapackage pre-flight grep

**Owns:** `packages/genealogy/composer.json`, `docs/specs/genealogy.md`.
**Depends on:** none.
**Blocks:** WP02, WP03.
**Authoritative surface:** `packages/genealogy/composer.json`.
**Execution mode:** `documentation`.
**Requirement refs:** FR-001, FR-002, FR-007, NFR-001, NFR-002, NFR-004, C-001, C-002.

### Subtasks

- T001 — Run pre-flight grep on `cms` / `core` / `full` composer.json files; capture transcript in activity log. If any match, transition to BLOCKED and file out-of-band note (FR-007).
- T002 — Edit `packages/genealogy/composer.json` `description` to begin `Distribution-extension package —` (FR-001).
- T003 — Insert DIR-004 banner block at top of `docs/specs/genealogy.md`, 3–6 lines, citing the charter directive explicitly (FR-002).
- T004 — Run `composer check-composer-policy` and `bin/check-package-layers`; both must exit 0.
- T005 — Commit + handoff.

## Work Package WP02: CLAUDE.md surfacing + extraction-log entry

**Owns:** `CLAUDE.md`, `docs/specs/extraction-log.md`.
**Depends on:** WP01.
**Blocks:** WP03.
**Authoritative surface:** `CLAUDE.md`.
**Execution mode:** `documentation`.
**Requirement refs:** FR-003, FR-004, FR-005, FR-006, FR-008, NFR-002, NFR-003, C-001, C-003.

### Subtasks

- T006 — Remove `genealogy` from the Layer 6 table row in `CLAUDE.md` (FR-004).
- T007 — Insert new `## Distribution Extensions` H2 section in `CLAUDE.md` after Layer Architecture, before Operation Checklists (FR-004).
- T008 — Update orchestration table row for `packages/genealogy/*` to carry `(distribution-extension)` annotation (FR-005).
- T009 — Insert new `## 2026-05 — genealogy distribution-extension reclassification` H2 in `docs/specs/extraction-log.md` with rationale / scope / what-changed / downstream-impact / layer-guard-reasoning / follow-ups subsections (FR-003, FR-008, C-003).
- T010 — Verify `.github/workflows/split.yml` line 78 unchanged (FR-006).
- T011 — Commit + handoff.

## Work Package WP03: verification gates + PR

**Owns:** verification transcripts; PR body.
**Depends on:** WP02.
**Blocks:** mission acceptance.
**Authoritative surface:** the PR.
**Execution mode:** `verification_and_pr`.
**Requirement refs:** FR-009, NFR-002, NFR-003, C-001.

### Subtasks

- T012 — Run all acceptance commands from `plan.md` §"Acceptance commands"; capture transcripts.
- T013 — Open PR with the title and body shape mandated by the plan; cite DIR-004, the extraction-log precedent entries, and the mission slug.
- T014 — Confirm CI is green (composer policy, layer guard, dead-code gate, getQuery bindings gate).
