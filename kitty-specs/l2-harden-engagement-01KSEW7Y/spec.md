# L2 Harden: waaseyaa/engagement

**Mission:** `l2-harden-engagement-01KSEW7Y`
**Status:** Stub
**Parent mission:** `l2-content-types-consolidation-01KSEFTX` · audit: `docs/audits/2026-05-l2-content-types-audit.md`

## Why this mission exists

`waaseyaa/engagement` was classified **alpha — needs hardening** in the L2 content-types audit (2026-05). Evidence:
- Zero `@api`-tagged classes: `EngagementAccessPolicy`, `Comment`, `Reaction`, and `Follow` entity classes are all untagged despite being the primary extension surface for social engagement.
- Admin SPA references are 2 i18n translation files only (fr.json, en.json) — no functional admin page components for managing comments, reactions, or follows.
- 5 test files for 3 entity types (Comment, Reaction, Follow) is borderline sparse; access policy coverage is unclear.
- No composable or list/edit admin page for engagement moderation.

Target Anokii surface: **Anokii Community / social layer**.

## Pre-resolved decisions

- Package stays at L2 (`"engagement": 2` in `bin/check-package-layers`).
- No code-breaking changes; hardening is additive.
- Follow M5A pattern for new admin SPA API surface.

## Suggested WPs

- WP01: Add `@api` on `EngagementAccessPolicy`, `Comment`, `Reaction`, `Follow`; verify dead-code gate passes.
- WP02: Expand tests to ≥ 8 files; ensure per-entity-type coverage + access policy unit tests.
- WP03: Add admin SPA engagement moderation pages (comment list/moderate, reaction summary); wire to JSON:API endpoints.
- WP04: Verify `bin/check-package-layers` green; confirm orchestration table references engagement spec.

## To be specified in implement-review

Real WP prompt files, acceptance criteria, and requirement IDs land in this mission's own `spec-kitty plan` + `spec-kitty tasks` invocations.
