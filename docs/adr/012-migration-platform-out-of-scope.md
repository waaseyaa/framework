# 012 — Migration platform: out of scope for the framework

**Status:** Superseded (2026-05-11) by [ADR 012a — Migration platform: substrate in core, source readers as packages](012a-migration-substrate-in-core.md)
**Mission:** Stability charter ratification
**Spec context:** `docs/specs/drupal-comparison-matrix.md` §6.3, §1.6

**Supersession note:** This ADR's verdict (migration out of scope) was reversed under a strategic reframe — parity with Drupal 12 and Laravel 14, where Drupal 12 ships Migrate as core and "migrate from WordPress / Drupal 7" becomes the framework's strongest user-acquisition lever. The reshape (rather than the full original A option) is "framework ships the substrate; source readers ship as packages." See ADR 012a for the current direction. This file is retained as the historical record of the cost-benefit analysis that initially placed migration out of scope.

## Context

Drupal's `migrate` module (core since 8.x) is how organizations leave Drupal 7. It defines source plugins (read foreign data), processor plugins (transform), destination plugins (write entities), a manifest format, dependency graphs, rollback, and an admin UI. It is large — comparable in scope to the entity system itself.

A Waaseyaa-shaped equivalent would be a multi-year mission. Consumer applications may keep an `Ingestion` namespace (`src/Ingestion/EntityMapper/*`) that imports external provider envelopes into entities. That remains app-shaped, not framework-shaped.

The decision: claim "destination for Drupal migrations" as a framework promise, or document that migration is an out-of-scope concern.

## Options considered

### A. Build framework-level migrate API

Source / processor / destination plugins, manifest, dependencies, rollback, admin UI. Multi-year. Framework grows by ~30%. Strong mission claim: "you can migrate your Drupal 7 site to Waaseyaa." Rejected: cost is real, and the consumer profile that wants this is not the consumer profile Waaseyaa is being built for.

### B. Ship `waaseyaa/migrate` as a separable framework package

Plugin-shaped, app-installable. Smaller than A. Defers the decision rather than making it; still implies framework ownership. Rejected for v0.x.

### C. Out of scope (CHOSEN)

Migration is not a framework concern. Apps own ingestion via their own ETL pipelines. Document the position. Provide a cookbook for "writing an ingestion pipeline" using existing framework primitives (entity storage, lifecycle events, queue API).

## Decision

Migration is **not a framework concern** in v0.x or v1.0. The position is documented and committed publicly so consumers do not build around a contract that will not arrive.

### What the framework provides

- `EntityStorage::create()` / `save()` — the destination call.
- Entity lifecycle events (ADR 011) — hookable for post-import side effects.
- Queue API — for batched/long-running imports.
- CLI — for invoking ingestion commands.

These primitives are sufficient for app-level ETL. The Minoo `Ingestion` package is the reference implementation.

### What the framework will NOT provide

- A source/processor/destination plugin contract.
- A migration manifest format (distinct from the schema migration manifest in `extra.waaseyaa.migrations`, which is for DB schema, not data import).
- A rollback API for data imports.
- An admin UI for migration management.
- Drupal-source-format readers.

### Documentation deliverable

A spec `docs/specs/app-level-ingestion.md` ships as part of this ADR's acceptance. It covers:

- The "ingestion pipeline" pattern (envelope → mapper → entity → storage).
- How to use lifecycle events for side effects.
- How to batch via Queue API.
- How to write idempotent importers.
- A worked example based on Minoo's `Ingestion` namespace.

The spec is **app-author-facing guidance**, not stable framework API.

### External tool recommendation

For Drupal-7-source migrations specifically, the recommended approach is external ETL: a Python or PHP script that reads Drupal source data, transforms it, and POSTs to the Waaseyaa app's ingestion endpoint or invokes a CLI command. The `indigenous-harvesters` Python project (Minoo's harvesting pipeline) is the pattern.

## Consequences

- **Framework grows smaller.** Multi-year mission avoided.
- **Drupal-7 magazine-shop migrations are not the target consumer.** This narrows the addressable market; it does not eliminate it (the entity model, AI-first stack, and modern PHP are independent value props).
- **Apps that need migration tooling build it themselves or reuse Minoo's pattern.** Cross-app reuse of ingestion code happens via shared composer packages between consumer apps, not via framework plugins.
- **The "migration platform" promise is not made.** A consumer expecting `drush migrate:import` will not find it. The position is documented so they are not surprised.
- **Re-evaluation gate:** if three or more consumer apps independently build similar ingestion pipelines, the question reopens. Framework features earn their place by repeated demand, not anticipation.

## References

- Matrix: `docs/specs/drupal-comparison-matrix.md` §1.6, §6.3.
- Minoo's `Ingestion` namespace: `waaseyaa/minoo/src/Ingestion/*` (reference implementation pattern).
- `indigenous-harvesters` Python project — external ETL pattern.
- Related: ADR 011 (lifecycle events used by ingestion side effects), ADR 010 (storage coordinator is the destination interface).
