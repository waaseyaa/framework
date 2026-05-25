# Specification Quality Checklist: Anokii Distribution Scaffold

**Feature**: [spec.md](../spec.md)

## Content Quality
- [x] Mission scope crisply distinguishes scaffolding (this mission) from product implementation (future Anokii missions)
- [x] Framework-vs-distribution boundary is stated and the dependency direction (Anokii → Waaseyaa) is unambiguous
- [x] Pre-resolved decisions list is exhaustive (license, palette, accessibility target, offline substrate, pilot Nations, language scope, forms queue, identity offline, governance-aware a11y, surface scope at v0.1)
- [x] Zero questions to Russell; zero timeline language

## Requirement Completeness
- [x] FR/NFR/Constraint separated, IDs unique
- [x] FR-008..FR-010 explicitly cover the ten artifact drafts (eight surfaces + two cross-cutting)
- [x] FR-009 requires explicit cross-referencing of framework DIR-IDs, Anokii DIR-A IDs, and gap-matrix capability rows in every draft
- [x] NFR-003 forbids timeline language in artifact drafts (alpha-to-beta-plan owns timing, not specs)
- [x] NFR-004 pins the deep-teal palette to exact hex values per framework CLAUDE.md §Code Style
- [x] C-001 prevents Waaseyaa `packages/` drift from this mission
- [x] C-002 prevents WP04 from accidentally running `spec-kitty specify` in the Anokii repo

## Filing Readiness
- [x] Lightweight kitty-specs-only filing — no GitHub issue at framework level
- [x] Gated by `charter-amendment-anokii-track-01KSEFE0` (DIR-004..DIR-008 referenced by drafts)
- [x] WP01..WP04 form a clean dependency chain with one parallel arm (WP03 || WP04 after WP02)
- [x] Owned files for WP01..WP03 are in the Anokii repo; owned files for WP04 are in this mission's lane in the Waaseyaa repo — no overlap
- [x] Pattern reference (`ai-observability-dashboard-01KSE9BX`) gives implementers an exact shape to mirror for the lane artifacts

## Cross-cutting / governance
- [x] Framework DIR-004 (OCAP-by-architecture) — inherited by every surface draft via DIR-A005
- [x] Framework DIR-005 (two-axis storage) — inherited by drive/docs/sheets drafts + offline-first cross-cutting draft (Dexie composite key maps to `(entity_id, langcode, vid)`)
- [x] Framework DIR-006 (codified policy gates) — referenced by admin-centre draft
- [x] Framework DIR-007 (Nuxt SPA bet) — implicit in all surface drafts (Anokii consumes `packages/admin`)
- [x] Framework DIR-008 (GPL-2.0-or-later) — inherited verbatim by Anokii DIR-A004
- [x] Anokii DIR-A001 (AODA AA) — mirrored in `aoda-aa-baseline.spec.md` + per-surface AODA sections
- [x] Anokii DIR-A002 (offline-first) — mirrored in `offline-first.spec.md` + per-surface offline sections
- [x] Anokii DIR-A003 (translation pipeline) — referenced in Co-Intelligence + admin-centre drafts; pilot Nations + dialect scope pre-resolved

## Decision preference order (per Wave 1+)
- [x] preserve OCAP audit lineage > minimise vendor lock-in > don't break codified policy gates — referenced explicitly in spec.md and called out for implementer judgment calls
