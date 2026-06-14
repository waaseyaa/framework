# Specification Quality Checklist: Queue + Scheduler Admin

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-05-23
**Feature**: [spec.md](../spec.md)

## Content Quality

- [x] No implementation details that don't belong in a spec (route paths and method signatures intentionally included — this is a wiring mission against existing contracts)
- [x] Focused on operator value (visibility into queue + scheduler runtime)
- [x] Written so a reviewer can diff against M4A-1 (PR #1429) and see structural symmetry
- [x] All mandatory sections completed

## Requirement Completeness

- [x] No `[NEEDS CLARIFICATION]` markers remain
- [x] Requirements are testable and unambiguous
- [x] Requirement types are separated (FR / NFR / C)
- [x] IDs are unique across FR-###, NFR-###, and C-### entries
- [x] All requirement rows include a priority value
- [x] Non-functional requirements have observable criteria (mirror M4A-1's response shape; per-request container resolution)
- [x] Acceptance criteria are present per WP
- [x] Edge cases identified (empty FailedJobRepository, unknown scheduler task, `\Throwable` serialization, retry semantics)
- [x] Scope is clearly bounded — queued/in-flight jobs explicitly deferred via follow-up issue
- [x] Dependencies and assumptions identified (depends on M4A-1 patterns; no new policy classes; reuse `_role` route option)

## Feature Readiness

- [x] Each FR maps to a WP via `wps.yaml` `requirement_refs`
- [x] WP01 owns FR-001..FR-007 + FR-015 + FR-016
- [x] WP02 owns FR-008..FR-014 + FR-015 (the WP02-specific bullet)
- [x] Owned-file sets in `wps.yaml` are non-overlapping (no two WPs claim the same path)
- [x] `docs/specs/admin-spa.md` is owned by WP02 (stamp + route registration), not WP01

## Mission Sizing

- WP01 estimated ~350 LOC (controller + 4 frontend files + tests)
- WP02 estimated ~250 LOC (scheduler addition + controller + 3 frontend files + tests + spec stamp)
- Each comfortably fits one PR + opus review pass.

## Cross-Mission Linkage

- Parent umbrella: GitHub #1414 (M4 admin SPA Phase 1)
- Direct tracker: GitHub #1471 (M4B)
- Closes audit rows: `docs/audits/admin-spa-modernization-2026-05-10.md` C-L0-01 + C-L0-02
- Sibling open work: M4A-5 (#1470), M4C (#1472), M5 (#1415)
- Follow-up filed by this mission: `TransportInterface::listJobs()` + queued/in-progress dashboard columns (filed during WP01 wrap-up)

## Filing Readiness

- [x] Spec, plan, wps.yaml, and both WP prompt files exist on disk
- [x] Lightweight filing pattern (kitty-specs only, no M-NNN doctrine entry per memory `feedback_spec_kitty_mission_filing_pattern`)
- [x] meta.json populated by `spec-kitty specify` — no manual edits needed
- [x] No docs/specs spec needed (this mission is purely admin-surface; existing `docs/specs/admin-spa.md` gets a routes update in WP02, no new doctrine spec required)

## Notes

- **Defer rationale (C-001):** queued/in-flight job listing requires a new method on `TransportInterface` + two transport implementations + contract tests. That's a layer-affecting contract change; it does not fit the "MVP dashboard for operators" frame of M4B. Filing it as a follow-up isolates the contract change from the dashboard wiring.
- **Pattern parity (NFR-001/NFR-002):** the entire mission's risk is concentrated in *deviating* from M4A-1. The reviewer's job is largely structural — diff against PR #1429 and look for shape symmetry. If sonnet's implementation diverges, ask why.
- **`\Throwable` serialization (T009, risk log):** `ScheduleRunResult` carries a `\Throwable` on failure. The controller MUST extract `message` + `class` and never let the Throwable cross the JSON boundary. This is the single most-likely-to-be-missed safety check.
