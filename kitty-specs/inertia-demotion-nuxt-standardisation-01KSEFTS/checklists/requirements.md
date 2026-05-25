# Specification Quality Checklist: Inertia Demotion + Nuxt Standardisation

**Feature**: [spec.md](../spec.md)

## Content Quality
- [x] Mission scoped to a single coherent goal: ratify DIR-007 in the framework manifest by demoting waaseyaa/inertia from full's require to suggest, with the README and admin-spa spec carrying the narrative signal. No scope creep into removing code or editing consumer apps.
- [x] "Why this mission exists" grounded in concrete prior decisions: DIR-007 from the parallel charter-amendment mission; assumptions.md note that Inertia is a "genuinely interesting alternative bet" worth keeping; the inventory note that `packages/inertia` exists today as a peer L6 adapter with no demotion signal.
- [x] Cross-mission dependency on `charter-amendment-anokii-track-01KSEFE0` (DIR-007 must exist as a referenceable identifier) explicit in Acceptance.
- [x] In-scope and out-of-scope explicitly enumerated; code removal, service-provider deprecation, consumer-app edits, GraphQL demotion, and charter edits all out-of-scope.
- [x] Risks listed with mitigations: implementer-removes-code (C-001 + owned files allowlist), banner-paraphrasing (C-003 + reviewer diff against plan.md), stale composer.lock (FR-004), policy-gate rejection (NFR-002), audit-misses-a-spec (FR-007 paper trail), unnecessary CLAUDE.md edits (NFR-001 clarification).
- [x] Decisions pre-resolved: demotion-not-removal, `suggest`-not-`replace`-not-`conflict`, no `@deprecated` markers, three WPs in one mission (not split).
- [x] Decisions deferred to implementer: exact wording of any docs/specs/* audit edit, whether `packages/inertia/composer.json`'s description needs an update.

## Requirement Completeness
- [x] FR/NFR/C separated, IDs unique (FR-001..FR-008, NFR-001..NFR-003, C-001..C-003).
- [x] Acceptance criteria present and gate-mapped: every codified gate (cs-check, phpstan, check-composer-policy, check-package-layers, check-dead-code, check-getquery-bindings) listed as a green-required gate.
- [x] Edge cases: existing `packages/inertia/composer.json` description already conveys optional status (conditional edit, FR-002 deferral), existing `packages/admin/README.md` already attributes primary UI (conditional edit, FR-006 explicit "or already does — verify, do not duplicate"), audit finds zero problematic specs (FR-007's "no spec edits required" alternative).
- [x] C-003 explicitly demands character-for-character match against plan.md §1 (banner) and §2 (suggest description). Reviewer focus reinforces this with a manual diff step.

## Filing Readiness
- [x] Mission scaffold materialized in `kitty-specs/inertia-demotion-nuxt-standardisation-01KSEFTS/`.
- [x] `spec.md`, `plan.md`, `tasks.md`, `wps.yaml`, `tasks/WP01..WP03-*.md`, `checklists/requirements.md` all populated.
- [x] Three WPs, all with `execution_mode: documentation` (no code changes anywhere).
- [x] WP01 sequential (source-of-truth README banner); WP02 + WP03 parallel after WP01.
- [x] Each WP file has YAML frontmatter matching `tasks/README.md` format and references the spec's FR/NFR/C IDs in `requirement_refs`.
- [x] Reviewer focus enumerated in plan.md: no code deletion (`git diff --stat` empty in inertia source/tests), exact-match wording, full's require no longer lists inertia, lock regenerated, WP01 lands before WP02, audit list in commit message.
- [x] No GitHub issue required — mission is documentation + manifest only; the CHANGELOG entry is the public signal.
