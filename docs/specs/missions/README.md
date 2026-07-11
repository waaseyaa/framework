# Waaseyaa Mission Manifest

> **Historical (Spec Kitty retired 2026-07-06).** This manifest and its `mission.json`
> files are a frozen record of the May-2026 mission-planning surface. Active work
> follows the anchor-issue + design-first workflow (`docs/specs/workflow.md`).

**Generated:** 2026-05-11
**Updated:** 2026-05-15 — **M-007** (`listing-pipeline-v1`) filed as ready (Spec Kitty mission `01KRMN0B`). Files the second of M-004's two prerequisites, in spec form. M-005 (`waaseyaa/migrate-source-wordpress`) shipped 2026-05-14 (squash `8da18d163`). M-002 close-out 2026-05-14 (#1481). M-006 remains shipped (squash `0f7e1809a`); M-001 remains shipped (squash `509e31fb7`).
**Status:** Seven missions on the manifest. M-001, M-002, M-005, M-006 shipped. M-003 ready. M-004 PARTIALLY UNBLOCKED (waits on M-007 to ship + spec revalidation). M-007 ready for filing — implement-review loop can start once charter §3.2/§5 amendments are sequenced.

Each subdirectory contains a `mission.json` filing-ready metadata file. The canonical spec for each mission lives at the path given in `mission.json:spec_path`.

## Mission roster

| ID | Title | Spec | Status | Files in `docs/specs/missions/<dir>/` |
|---|---|---|---|---|
| M-001 | Entity Storage v2 | `docs/specs/entity-storage-v2.md` | **shipped 2026-05-11** (squash `509e31fb7`) | `mission.json` |
| M-002 | Migration Platform v1 | `docs/specs/migration-platform-v1.md` | ready | `mission.json` |
| M-003 | Config Management v1 | `docs/specs/config-management-v1.md` | ready (verify validation pipeline) | `mission.json` |
| M-004 | Two-Axis Translation × Revisions | `docs/specs/entity-storage-translatable-revisions.md` | blocked (waits on listing-pipeline-v1; single-axis-translation prereq cleared by M-006 on 2026-05-13) | `mission.json` |
| M-005 | WordPress Source Reader | `docs/specs/waaseyaa-migrate-source-wordpress.md` | blocked (waits on M-002) | `mission.json` |
| M-006 | Entity Storage — Single-Axis Translations v1 | `docs/specs/entity-storage-translations-v1.md` | **shipped 2026-05-13** (squash `0f7e1809a`); satisfies charter §3.2 beta-entry criterion 9 (per-field translation) | `mission.json` |
| M-007 | Listing Pipeline v1 — Views-equivalent surface | `docs/specs/listing-pipeline-v1.md` | ready-for-filing 2026-05-15; Spec Kitty mission `listing-pipeline-v1-01KRMN0B`; adds charter §3.2 criterion 10 + new §5.X (listing) + §5.Y (cache tag/context). Clears M-004's second prerequisite when shipped. | `mission.json` |

## Cross-mission dependency graph

```
                          ┌─────────────────────────┐
                          │  Charter ratification   │
                          │  (Russell merges §12.4) │
                          └────────────┬────────────┘
                                       │
                            ┌──────────┴───────────┐
                            ▼                      ▼
                       M-001 starts          M-003 starts (independent)
                       (no prereqs)          (verify validators first)
                            │
            ┌───────────────┼──────────────┐
            │               │              │
            ▼               ▼              ▼
         WP01-WP04      WP04 + WP08    WP05-WP12
            │           shipped first
            │               │
            │               ▼
            │        M-002 WP05 unblocked
            │               │
            │       ┌───────┼────────┐
            │       ▼       ▼        ▼
            │   M-002 WPs 06,07,08,09 (parallel)
            │           │
            │           ▼
            │       M-002 WP11 + WP12 (close)
            │           │
            │           ▼
            │     M-005 starts (WordPress reader)
            │     (separate package, separate repo)
            ▼
         M-001 shipped 2026-05-11
            │
            ▼
   M-006 shipped 2026-05-13 (single-axis translations — BETA-GATE cleared)
            │
            │   ┌── M-007 listing-pipeline-v1 (spec filed 2026-05-15; impl pending)
            │   │
            ▼   ▼
       prereq 1 satisfied (M-006 translation: shipped); prereq 2 spec-only (M-007: ready)
            │
            ▼
         M-004 PARTIALLY UNBLOCKED (waits on M-007 ship + §3/§7 revalidation)
```

## Filing order (recommended)

1. **M-001** — Entity Storage v2. No prereqs. WP01 starts immediately.
2. **M-003** — Config Management v1. Standalone after verifying `FieldDefinition::validators()` is shipped. Can run fully in parallel with M-001.
3. **M-002** — Migration Platform v1. WPs 01–04, 09, 10 can start in parallel with M-001 / M-003. WP05 waits on M-001 WP04+WP08.
4. **M-005** — WordPress Source Reader. Starts after M-002 acceptance criterion 8 satisfied. Lives in a separate composer package + separate repo.
5. **M-007** — Listing Pipeline v1. Largest single feature post-charter; owns cache tag/context architecture alongside Views-equivalent listings. Spec filed 2026-05-15; ready for `spec-kitty plan`. Charter §3.2 criterion 10 + new §5.X + §5.Y amendments land in the mission's documentation WP12.
6. **M-004** — Two-Axis Translation × Revisions. Most dependency-blocked; files now for visibility but does not start work until M-007 ships AND §3 FRs + §7 WP decomposition are revalidated against the M-006 + M-007 substrates that actually shipped.

## Agent assignments (uniform across all missions)

```yaml
implementer: sonnet
reviewer: opus
escalation_after_n_rejections: 2
escalation_target: opus-as-implementer
```

Per Spec Kitty implement-review loop:
- Sonnet receives a WP brief and produces an implementation.
- Opus reviews. Approves → WP done. Rejects with feedback → Sonnet revises.
- After two consecutive Opus rejections of Sonnet's work on the same WP, escalation triggers: Opus takes over implementation; the cycle continues with a different (possibly human) reviewer or arbiter.

## Pre-filing readiness checklist

For each mission to be filed:

- [x] Spec drafted and ratified internally (status: `draft-spec`)
- [x] Governing ADRs accepted (010–018, 012 superseded by 012a)
- [x] Charter governs the surface (charter §5 + §3.2 + §10 cross-refs)
- [x] Agent assignments encoded in mission.json + spec YAML metadata
- [x] External dependencies documented in mission.json
- [x] Validation consumer named
- [x] Work-package count and parallelizability documented
- [x] Estimated breaking-change count assessed (all zero — additive surfaces)

## Charter ratification status

- **Charter status (top of `docs/specs/stability-charter.md`):** Ratification-ready
- **§11 questions:** All twelve resolved (2026-05-11)
- **CI infrastructure:** Authored at `.github/workflows/surface-parity.yml` + `changelog-discipline.yml`, scripts at `tools/check-surface-parity.php` + `tools/check-changelog-discipline.sh`
- **Remaining for ratification:** `@jonesrussell` merges the ratification PR with the alpha-train tag commit message per charter §12.4

## What this manifest enables

Once `spec-kitty.specify` is invoked against each `mission.json`, Spec Kitty registers the missions in its runtime state. The `spec-kitty-implement-review` skill then begins the implement-review loop, dispatching Sonnet for implementation and Opus for review per the per-mission `agent_assignments` block.

For autonomous execution: file M-001 first, start its WP01 implement-review loop, and let dependency-aware sequencing advance through the four missions over ~6–9 months of parallel work.
