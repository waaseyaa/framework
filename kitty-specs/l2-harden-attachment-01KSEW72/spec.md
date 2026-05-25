# L2 Harden: waaseyaa/attachment

**Mission:** `l2-harden-attachment-01KSEW72`
**Status:** Stub
**Parent mission:** `l2-content-types-consolidation-01KSEFTX` · audit: `docs/audits/2026-05-l2-content-types-audit.md`

## Why this mission exists

`waaseyaa/attachment` was classified **alpha — needs hardening** in the L2 content-types audit (2026-05). Evidence:
- No README (self-documentation gap).
- Zero `@api`-tagged classes despite being an extension surface (the Attachment entity class and at-most-one-active invariant are not marked for third-party use).
- Admin SPA integration is indirect (via field-form layer), not wired as a standalone admin consumer.
- 4 test files cover the happy path but the at-most-one-active invariant is not explicitly tested.

Orchestration context: the CLAUDE.md orchestration table routes `packages/attachment/*` to `docs/specs/work-surface.md`, meaning its contracts are embedded in the work-surface spec rather than being self-documenting.

## Pre-resolved decisions

- Package stays at L2 (`"attachment": 2` in `bin/check-package-layers`).
- No code-breaking changes; hardening is additive (README, `@api` tags, tests).
- Follow M5A pattern for any new API surface additions.

## Suggested WPs

- WP01: Add `README.md` covering entity type, at-most-one-active invariant contract, consumer guide.
- WP02: Add `@api` on public classes (`Attachment` entity, invariant enforcer if extracted); ensure dead-code gate passes.
- WP03: Add/expand tests for the at-most-one-active invariant; target ≥ 6 test files.
- WP04: Validate admin SPA integration (either wire standalone attachment admin or confirm work-surface spec covers it; update orchestration table).

## To be specified in implement-review

Real WP prompt files, acceptance criteria, and requirement IDs land in this mission's own `spec-kitty plan` + `spec-kitty tasks` invocations.
