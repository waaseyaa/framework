# L2 Harden: waaseyaa/groups

**Mission:** `l2-harden-groups-01KSEW7E`
**Status:** Stub
**Parent mission:** `l2-content-types-consolidation-01KSEFTX` · audit: `docs/audits/2026-05-l2-content-types-audit.md`

## Why this mission exists

`waaseyaa/groups` was classified **alpha — needs hardening** in the L2 content-types audit (2026-05). Evidence:
- Zero `@api`-tagged classes despite the multi-bundle design (GroupType + Group) being a clear third-party extension surface.
- Only 3 test files for a 2-entity-type package (Group + GroupType) with app-defined bundle cardinality.
- Admin SPA integration is limited to i18n translation strings and a `useNavGroups.ts` navigation composable — no group management list/edit pages.
- No admin page components for group type management.

Target Anokii surface: **Anokii Communities**.

## Pre-resolved decisions

- Package stays at L2 (`"groups": 2` in `bin/check-package-layers`).
- No code-breaking changes; hardening is additive.
- Follow M5A pattern for new admin SPA API surface.

## Suggested WPs

- WP01: Add `@api` on `Group`, `GroupType` entity classes and any public service interfaces; verify dead-code gate passes.
- WP02: Expand tests to ≥ 6 files; cover bundle registration and GroupType lifecycle.
- WP03: Add admin SPA group management pages (list, create, edit group type); wire to JSON:API endpoints.
- WP04: Verify `bin/check-package-layers` green; update orchestration table if needed.

## To be specified in implement-review

Real WP prompt files, acceptance criteria, and requirement IDs land in this mission's own `spec-kitty plan` + `spec-kitty tasks` invocations.
