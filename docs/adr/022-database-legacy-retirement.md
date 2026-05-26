# ADR-022: database-legacy Package Retirement (ELIMINATE)

**Date:** 2026-05-25
**Status:** Accepted
**Supersedes:** ADR-007 (database-legacy package naming)
**Mission:** `database-legacy-retirement-01KSEFV2`

---

## Context

`packages/database-legacy/` was created during the early alpha period as a compatibility shim providing
`Waaseyaa\Database\*` symbols (primarily `Database`, `DatabaseInterface`, `QueryBuilder`, and related
classes) while the canonical DBAL-backed storage layer (`DBALDatabase`, `EntityRepository`) was
being built out. ADR-007 (2024) documented the intentional naming asymmetry: the directory is
`packages/database-legacy/` but the PHP namespace is `Waaseyaa\Database\` — chosen to give
consumers a migration window without namespace churn.

By the time of mission `database-legacy-retirement-01KSEFV2` (2026-05-25), the WP01 audit found:

- **307 total PHP files** in `packages/` and `tests/` that reference `Waaseyaa\Database\*`.
- **294 files** are inside `packages/database-legacy/` itself (the package's own source and tests).
- **13 files** are consumers outside the package (all in `packages/migration/`).
- **0 files** remain as (b) retained-for-bridge or (c) out-of-band-followup items.

The external consumer footprint is therefore limited to a single package. No runtime code outside
`packages/migration/` and `packages/database-legacy/` itself uses the legacy namespace.

DIR-003 (Greenfield Removal Policy) applies during the alpha phase: patterns that have been
superseded are removed outright without a deprecation cycle.

---

## Decision

**Eliminate** `packages/database-legacy/` from the monorepo.

Specifically:

1. All 13 files in `packages/migration/` that imported `Waaseyaa\Database\*` were migrated to the
   canonical `DBALDatabase` / `DatabaseInterface` surface (WP02, category (a) migrations).
2. The package directory (`packages/database-legacy/`) is deleted from the repository.
3. The Composer path-repository entry, the `"waaseyaa/database-legacy": "self.version"` require
   entry, and the `"Waaseyaa\\Database\\Tests\\"` autoload-dev mapping are removed from the root
   `composer.json`.
4. The monorepo-split matrix entry in `.github/workflows/split.yml` is removed.
5. `database-legacy` is removed from the Layer 0 Foundation package list in `CLAUDE.md`.

---

## Consequences

**Positive:**
- Eliminates a dead compatibility shim; reduces monorepo surface by 13 files.
- Removes the split-CI job for a package that no longer needs publishing.
- Simplifies the Layer 0 package graph.

**Negative / migration notes:**
- Any external consumer (e.g. minoo, claudriel) that declared `"waaseyaa/database-legacy"` as a
  direct Composer dependency must drop that dependency and migrate to `waaseyaa/foundation`
  (`DBALDatabase`, `DatabaseInterface`) or the appropriate entity-storage surface. The WP01 audit
  confirmed both external consumers are already alpha-pinned and can absorb the breaking change
  in the same release batch.
- The `Waaseyaa\Database\*` PHP namespace is retired. Code that still uses it will generate
  class-not-found errors at runtime.

**Supersedes ADR-007:** The naming rationale documented there (directory vs namespace asymmetry)
is now moot because the package itself no longer exists.

---

## Alternatives Considered

**RENAME (keep package, rename to `waaseyaa/database`):** Rejected. The package contains no
functionality not already present in `waaseyaa/foundation` (DBALDatabase) and
`waaseyaa/entity-storage`. Renaming would preserve dead weight and extend the migration timeline
without benefit.

**Deprecation cycle (keep for N releases):** Rejected. DIR-003 prohibits deprecation windows
during the alpha phase. External consumers are controlled (minoo, claudriel) and can be updated
atomically.
