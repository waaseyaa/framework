# Work Packages: database-legacy-retirement-01KSEFV2

_Generated from wps.yaml. Do not edit directly._

## Work Package WP01: Usage audit (no code edits)

**Owns:** `docs/audits/2026-05-database-legacy-usage.md`.
**Depends on:** none.
**Blocks:** WP02, WP03.
**Authoritative surface:** `docs/audits/2026-05-database-legacy-usage.md`.
**Execution mode:** `documentation`.
**Requirement refs:** FR-001, FR-002, FR-003, NFR-003, NFR-004, C-001.

### Subtasks

- T001 — Run the canonical inventory grep; capture file count and the file list.
- T002 — Open every file from the inventory; classify each callsite as (a) `migrate-to-target`, (b) `intentional-bridge`, or (c) `misuse-migrate-elsewhere` per the rubric in `plan.md`.
- T003 — Produce the per-package summary table (package, file count, (a)/(b)/(c) breakdown, recommended action).
- T004 — External-consumer scan: grep Minoo and Claudriel repos for `waaseyaa/database-legacy` and `Waaseyaa\Database\` usage; document findings.
- T005 — Author the disposition recommendation section. Recommendation MUST cite DIR-003 by name and be either ELIMINATE or RENAME.
- T006 — Commit the audit document.

## Work Package WP02: Bulk migration of (a) callsites + (c) follow-up filing

**Owns:** every (a) file from the WP01 audit; `occurrence_map.yaml` (mission root); out-of-band notes for (c) deferred items.
**Depends on:** WP01.
**Blocks:** WP03.
**Authoritative surface:** `occurrence_map.yaml`.
**Execution mode:** `bulk_edit`.
**Requirement refs:** FR-004, FR-005, NFR-001, NFR-002, NFR-005, C-002, C-003.

### Subtasks

- T007 — Invoke `spec-kitty-bulk-edit-classification`; produce `occurrence_map.yaml` covering every (a) file with `change_mode: namespace_rename_only` (or the narrower mode the audit specifies).
- T008 — Execute the namespace edits per the occurrence map, batched by package. After each batch: `bin/check-getquery-bindings` exits 0; PHPUnit for the owning package exits 0.
- T009 — Handle (c) `trivial` migrations in-line.
- T010 — File out-of-band Spec Kitty follow-up notes for every (c) `out-of-band-followup` item; one note per package containing the audit file/line range and the recommended target abstraction.
- T011 — Run the FR-004 verification grep; produce stragglers diff against the audit's "retained-or-followup" list (must be empty).
- T012 — Commit.

## Work Package WP03: Disposition (ELIMINATE or RENAME) + ADR + PR

**Owns (ELIMINATE path):** `packages/database-legacy/` (deletion), `.github/workflows/split.yml`, `CLAUDE.md`, root `composer.json` if applicable, `docs/adr/0NN-database-legacy-retirement.md` (new), `docs/audits/2026-05-database-legacy-usage.md` (`## Disposition` appended), `CHANGELOG.md`.
**Owns (RENAME path):** `packages/database-bridge/` (git mv from legacy), every `composer.json` requiring `waaseyaa/database-legacy`, `.github/workflows/split.yml`, `CLAUDE.md`, `docs/adr/0NN-database-legacy-rename.md` (new), `docs/audits/2026-05-database-legacy-usage.md` (`## Disposition` appended), `CHANGELOG.md`.
**Depends on:** WP02.
**Blocks:** mission acceptance.
**Authoritative surface:** the PR + the new ADR.
**Execution mode:** `code_change`.
**Requirement refs:** FR-006, FR-007, FR-008, FR-009, NFR-002, NFR-003, C-001, C-003, C-004.

### Subtasks

- T013 — Reviewer-of-record reads WP01 audit; confirms ELIMINATE (default) or RENAME (only if audit triggers a RENAME condition); records decision in the WP activity log.
- T014 (ELIMINATE) — Execute the steps in `plan.md` §"Path A — ELIMINATE": delete dir, edit split.yml / CLAUDE.md / root composer.json, write new ADR, append `## Disposition` section, add CHANGELOG `### Removed` entry.
- T015 (RENAME) — Execute the steps in `plan.md` §"Path B — RENAME": git mv, update package composer.json, update every requiring composer.json, edit split.yml / CLAUDE.md, write new ADR superseding ADR-007, append `## Disposition` section, add CHANGELOG `### Changed` entry.
- T016 — Run all verification commands (path-specific subset from `plan.md`).
- T017 — Open PR with title and body per `plan.md`; for ELIMINATE include `## Breaking Change` section.
- T018 — Confirm CI green.
