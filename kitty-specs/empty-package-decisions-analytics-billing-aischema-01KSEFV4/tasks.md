# Work Packages: empty-package-decisions-analytics-billing-aischema-01KSEFV4

_Generated from wps.yaml. Do not edit directly._

## Work Package WP01: analytics → audit rename

**Owns:** `packages/analytics/` → `packages/audit/` (git mv); the audit package's `composer.json`; every consumer's `composer.json` requiring `waaseyaa/analytics`; every PHP file consuming `Waaseyaa\Analytics\`; `.github/workflows/split.yml`; `CLAUDE.md` (L0 row + orchestration row); root `composer.json` if applicable; `docs/adr/0NN-analytics-renamed-to-audit.md` (new); `occurrence_map.yaml` (mission root).
**Depends on:** none.
**Blocks:** mission acceptance.
**Authoritative surface:** `packages/audit/`.
**Execution mode:** `bulk_edit`.
**Requirement refs:** FR-001, FR-002, FR-003, FR-004, FR-005, FR-006, FR-007, FR-008, FR-009, NFR-001, NFR-002, NFR-003, NFR-004, NFR-005, C-001, C-002, C-003.

### Subtasks

- T001 — Invoke `spec-kitty-bulk-edit-classification`; produce `occurrence_map.yaml` covering the rename.
- T002 — Decide Umami carving strategy (i embedded vs ii shim); record in activity log AND new ADR.
- T003 — `git mv packages/analytics packages/audit`.
- T004 — Edit `packages/audit/composer.json` per FR-002, FR-003, FR-004.
- T005 — Move/rename `UmamiClient.php` per chosen strategy; update its namespace declaration.
- T006 — Bulk-edit every `Waaseyaa\Analytics\` consumer per occurrence map.
- T007 — Update every `packages/*/composer.json` requiring `waaseyaa/analytics`.
- T008 — Edit `.github/workflows/split.yml` per chosen strategy.
- T009 — Edit `CLAUDE.md` L0 cell + orchestration row.
- T010 — Write new ADR `docs/adr/0NN-analytics-renamed-to-audit.md`.
- T011 — Run full verification suite (composer policy, layer guard, getQuery bindings, PHPUnit). All exit 0 / green.
- T012 — Commit + open PR.

## Work Package WP02: billing scaffold marking

**Owns:** every file in `packages/billing/src/` (PHPDoc-only edits where `@api` is missing); `packages/billing/README.md`.
**Depends on:** none.
**Blocks:** mission acceptance.
**Authoritative surface:** `packages/billing/README.md`.
**Execution mode:** `documentation`.
**Requirement refs:** FR-010, FR-011, FR-012, NFR-001, NFR-003, C-004.

### Subtasks

- T013 — Scan every `packages/billing/src/*.php` for class-level `@api` PHPDoc; add it where missing.
- T014 — Author `packages/billing/README.md` per `plan.md` shape; ensure length 80–200 lines and "Out of scope for v0.1" statement is present.
- T015 — Verify `CLAUDE.md` orchestration row already points at `packages/billing/README.md`; update if it shows `—`.
- T016 — Run verification (composer policy, layer guard, billing PHPUnit).
- T017 — Commit + open PR.

## Work Package WP03: ai-schema activation

**Owns:** `docs/specs/ai-schema.md` (new); `packages/ai-schema/README.md`; `CLAUDE.md` orchestration row for `packages/ai-*/*`.
**Depends on:** none.
**Blocks:** mission acceptance.
**Authoritative surface:** `docs/specs/ai-schema.md`.
**Execution mode:** `documentation`.
**Requirement refs:** FR-013, FR-014, FR-015, NFR-001, NFR-003, C-005.

### Subtasks

- T018 — Author `docs/specs/ai-schema.md` per the section contract in `plan.md`; length 120–250 lines.
- T019 — Update `packages/ai-schema/README.md` with spec link.
- T020 — Edit `CLAUDE.md` orchestration row for `packages/ai-*/*` to append `docs/specs/ai-schema.md`.
- T021 — Run verification (composer policy, layer guard).
- T022 — Commit + open PR.
