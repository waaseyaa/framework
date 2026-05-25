# Specification Quality Checklist: API Surface Consolidation — JSON:API primary

**Feature**: [spec.md](../spec.md)

## Content Quality
- [x] Mission scoped to a single coherent goal: ratify JSON:API as the framework's primary API surface and demote GraphQL to optional, with a complete parity matrix and follow-up mission slugs filed for every GAP.
- [x] "Why this mission exists" grounded in concrete prior decisions: alpha-to-beta-plan Wave-2 substrate-hardening item; de-facto state (every recent admin mission extends JSON:API; no recent mission extends GraphQL); CLAUDE.md orchestration table's no-specialist-skill listing for graphql.
- [x] Pre-resolved decision (demote, not remove) is grounded in the Hard Rules' decision-preference order: minimise vendor lock-in (preserve optionality) > don't break codified policy gates.
- [x] Twin shape with `inertia-demotion-nuxt-standardisation-01KSEFTS` explicitly called out for reviewer familiarity.
- [x] In-scope and out-of-scope explicitly enumerated; code removal, consumer-app edits, gap-fill implementation, charter directive, parallel-Inertia-mission scope all out-of-scope.
- [x] Risks listed with mitigations: implementer-removes-code (C-001 + owned-files allowlist), audit-partial (C-005 + reviewer cross-check via rg), README already demoted (FR-004/FR-005 conditional verify), GAP rows missing follow-up slugs (C-004), consumer transitively dependent on GraphQL (CHANGELOG migration note), policy-gate rejection (NFR-002).
- [x] Decisions pre-resolved: demote-not-remove, JSON:API primary not co-equal, matrix in jsonapi.md not separate file, gap-fills as separate missions, no charter directive added.
- [x] Decisions deferred to implementer: follow-up mission slug naming per gap, optional extra columns in matrix, conditional description edit in graphql composer.json.

## Requirement Completeness
- [x] FR/NFR/C separated, IDs unique (FR-001..FR-009, NFR-001..NFR-003, C-001..C-005).
- [x] Acceptance criteria present and gate-mapped (every codified gate listed).
- [x] Edge cases: zero gaps in audit (Out-of-band placeholder has explicit zero-gap sentence), GraphQL README already demoted (conditional edit), graphql composer.json description already optional-signalling (conditional edit).
- [x] C-003 demands character-for-character match against plan.md §1 (banner) and §2 (suggest description).
- [x] C-005 demands audit completeness; reviewer cross-checks via `rg` on the schema.
- [x] C-004 demands every GAP row has a populated follow-up mission slug AND the corresponding scaffold exists at `kitty-specs/<slug>/spec.md`.

## Filing Readiness
- [x] Mission scaffold materialized in `kitty-specs/api-surface-consolidation-jsonapi-primary-01KSEFTV/`.
- [x] `spec.md`, `plan.md`, `tasks.md`, `wps.yaml`, `tasks/WP01..WP03-*.md`, `checklists/requirements.md` all populated.
- [x] Three WPs, all `execution_mode: documentation`.
- [x] WP01 sequential (JSON:API primary declaration); WP02 + WP03 parallel after WP01.
- [x] Each WP file has YAML frontmatter matching `tasks/README.md` format and references the spec's FR/NFR/C IDs in `requirement_refs`.
- [x] Reviewer focus enumerated in plan.md: no code deletion (`git diff --stat packages/graphql/src` empty), exact-match wording, full requires GraphQL no more, lock regenerated, audit completeness (rg cross-check), gap-fills as separate missions, Out-of-band post-edit with real slugs.
- [x] No GitHub issue required — mission is documentation + manifest only; the CHANGELOG entry is the public signal.
