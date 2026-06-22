# Specification Quality Checklist: L2 Content-Types Consolidation

**Feature**: [spec.md](../spec.md)

## Content Quality
- [x] Mission scoped to a single coherent goal: audit + classify every L2 package, file per-package follow-up mission scaffolds for needs-hardening + dead-proposed packages, and graduate messaging from L2 to L3 as a chat substrate. No scope creep into actually-doing the hardening, removal, or building the chat surface.
- [x] "Why this mission exists" grounded in concrete prior decisions: alpha-to-beta-plan substrate-hardening wave; gap-matrix C-1 (chat surface) driving the messaging graduation; inventory + CLAUDE.md layer table listing as the canonical scope.
- [x] Pre-resolved architectural decisions are explicit: no metapackage consolidation; messaging → L3; engagement stays L2; one architectural change only.
- [x] In-scope and out-of-scope explicitly enumerated; per-package hardening, dead-package removal, chat surface building, metapackage merging, engagement movement, other-package layer changes, and charter directive editing all out-of-scope.
- [x] Risks listed with mitigations: over-generous audit (NFR-003 + reviewer cross-check), over-aggressive removal proposals (proposals are not removals; charter-bearing follow-up per package), layer gate fails post-graduation (gate is the test; document + resolve), messaging consumers assume L2 semantics (audit covers consumer surfaces), chat surface conflation (Out-of-scope explicit), CLAUDE.md format errors (reviewer diffs against existing format).
- [x] Decisions pre-resolved: each L2 package stays separate; messaging → L3; engagement L2; no new specs for healthy packages in this mission; audit in docs/audits/; per-package removal missions not bulk.
- [x] Decisions deferred to implementer: per-package classification (the audit work); follow-up mission stub wording; whether attachment + structured-import are L2 at edit time; whether messaging routes need a Routing-package lift.

## Requirement Completeness
- [x] FR/NFR/C separated, IDs unique (FR-001..FR-009, NFR-001..NFR-003, C-001..C-005).
- [x] Acceptance criteria present and gate-mapped (every codified gate listed).
- [x] Edge cases: attachment + structured-import inclusion conditional on the layer table; zero needs-hardening packages (Out-of-band has the explicit zero-packages sentence); messaging routes already at L4 (no route-lift needed); messaging consumer at L1/L2 (gate failure path documented).
- [x] C-001 forbids L2 source changes (except messaging README, a metadata edit).
- [x] C-003 demands rationale per classification.
- [x] C-004 limits architectural change to messaging only.
- [x] C-005 makes follow-ups scaffolds, not full missions.
- [x] NFR-003 makes the audit reproducible (sources listed; reviewer cross-check).

## Filing Readiness
- [x] Mission scaffold materialized in `kitty-specs/l2-content-types-consolidation-01KSEFTX/`.
- [x] `spec.md`, `plan.md`, `tasks.md`, `wps.yaml`, `tasks/WP01..WP03-*.md`, `checklists/requirements.md` all populated.
- [x] Three WPs with dependencies: WP01 first (audit drives everything else); WP02 depends on WP01 (classification → follow-up scaffolds); WP03 depends on WP01 (audit captures pre-graduation state).
- [x] Mixed execution_mode: WP01 + WP02 are `documentation`; WP03 is `code_change` because it touches `bin/check-package-layers` (executable script).
- [x] Each WP file has YAML frontmatter matching `tasks/README.md` format and references the spec's FR/NFR/C IDs in `requirement_refs`.
- [x] Reviewer focus enumerated in plan.md: audit reproducibility, every classification has rationale, no L2 source changes, only messaging changes layer, layer gate green, follow-ups are scaffolds, Out-of-band post-edited with real slugs.
- [x] Layer-gate verification gate explicit in WP03 — `bin/check-package-layers` runs against the new layer map and must be green for acceptance.
