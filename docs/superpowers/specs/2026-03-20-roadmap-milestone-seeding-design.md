# Roadmap Milestone Seeding Design

Date: 2026-03-20

## Goal

Populate the currently empty roadmap milestones with a lean but complete set of execution-ready issues so the milestone board reflects real planned work instead of placeholders.

## Scope

Milestones covered:

- `v1.6 — Search Provider`
- `v1.7 — Revision System`
- `v1.8 Projects & Workspaces`
- `v1.9 — Production Queue Backend`
- `v2.0 — Schema Evolution`

## Design Principles

- Prefer a small number of execution-ready issues over placeholder issues.
- Each milestone should have enough issues to describe a credible delivery path.
- Near-term milestones should map directly to existing code seams and specs.
- Farther-term milestones should still be concrete, but should start with boundary-setting design work where contracts are not yet codified.

## Recommended Issue Shape

Each milestone gets three issues:

1. Design issue
   - Lock interfaces, lifecycle rules, and subsystem boundaries.
2. Implementation issue
   - Deliver the core feature or infrastructure.
3. Verification issue
   - Add deterministic integration/regression coverage proving milestone readiness.

This keeps the roadmap small while still making each milestone actionable.

## Proposed Issues

### v1.6 — Search Provider

1. `Design: specify SQLite FTS5 search provider contract and indexing lifecycle`
   - Define provider behavior against `SearchProviderInterface`.
   - Specify index ownership, rebuild semantics, filters, and facets.
2. `Implement: add SQLite FTS5 SearchProvider with indexing and query execution`
   - Deliver the concrete provider and service wiring.
   - Support indexing, query execution, and rebuild operations.
3. `Test: integration coverage for search indexing, filters, facets, and rebuilds`
   - Verify deterministic indexing and query behavior end to end.

### v1.7 — Revision System

1. `Design: define revision lifecycle for RevisionableInterface entities`
   - Specify revision creation, retrieval, publish/draft expectations, and rollback semantics.
2. `Implement: add RevisionableStorageInterface support in entity storage`
   - Add revision persistence and retrieval to storage flows and kernel wiring.
3. `Test: regression coverage for revision creation, retrieval, and rollback semantics`
   - Verify history creation, revision reads, and restoration behavior.

### v1.8 Projects & Workspaces

1. `Design: specify project/workspace model and kernel isolation boundaries`
   - Define project/workspace concepts, isolation rules, and what remains global.
2. `Implement: add project/workspace context resolution to kernels and admin surface`
   - Resolve current context in HTTP/CLI and expose scoped admin behavior.
3. `Test: integration coverage for project/workspace isolation across config, entities, and routing`
   - Prove tenant/workspace state does not leak across contexts.

### v1.9 — Production Queue Backend

1. `Design: specify durable queue backend contract, retries, and failure handling`
   - Define delivery semantics, retries, failed-job behavior, and worker expectations.
2. `Implement: add durable queue backend and worker processing command`
   - Deliver a production-capable queue driver and operator-facing worker loop.
3. `Test: integration coverage for retries, unique jobs, rate limits, and failed-job recording`
   - Verify current queue attributes and failure handling on the durable backend.

### v2.0 — Schema Evolution

1. `Design: specify field-definition diffing and migration generation rules`
   - Define safe vs destructive changes and migration review boundaries.
2. `Implement: add schema diff engine and migration generation for entity field changes`
   - Generate migration plans/artifacts from field-definition changes.
3. `Test: regression coverage for additive, rename-like, and destructive schema changes`
   - Verify deterministic handling of allowed and blocked changes.

## Rationale By Milestone

- `v1.6` already has a stable interface surface in `packages/search/` but no concrete provider.
- `v1.7` already has revision contracts referenced in the entity system spec, so the missing work is lifecycle definition plus storage implementation.
- `v1.8` has the least codified framework support today, so it should begin by locking boundaries before implementation.
- `v1.9` builds on the existing queue package, which already provides in-memory and synchronous primitives but not a production backend.
- `v2.0` is already described at the milestone level as schema diffing and migration generation, so the main work is codifying rules and delivering deterministic tooling.

## Out of Scope

- Breaking each milestone into many fine-grained subtasks now.
- Creating implementation plans for each milestone in this step.
- Writing code for any milestone feature.

## Next Step

After user review, create the GitHub issues with:

- titles matching this design
- milestone assignments
- labels aligned to the subsystem
- bodies with scope, acceptance criteria, and verification expectations
