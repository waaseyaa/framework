# Search index trust boundary plan

Issue: #2211

Program: #2198

Prerequisite: #2188

## Decision

Waaseyaa's FTS5 tables are a protected, derived datastore. They may contain the
complete application-supplied search projection and therefore inherit the
strongest classification of any indexed value. They are not a public content
cache and must receive the same filesystem, database, backup, diagnostic, and
operator controls as canonical entity storage.

The parked FTS5 provider remains the sole production reader until #2192 replaces
its boolean access check with an explicit-principal safe projection. This work
package closes the paths that can fail open before that promotion:

1. `Fts5SearchProvider` must require a `SearchAccessChecker`; construction
   without one is impossible.
2. `EntitySearchAccessChecker` must default-deny unknown and non-entity sources.
   A later public source must register an explicit resolver under #2192; indexed
   metadata is never authority for publicness.
3. A repository architecture test owns the complete first-party production-PHP
   reader inventory for `search_index` and `search_metadata`. Only the indexer
   may write them and the parked FTS5 provider may read them. Dynamic-name and
   non-PHP evasions remain explicit code-review obligations.
4. Package documentation records the datastore, backup, logging, cache, and
   alternate-provider obligations. No query result may be cached across acting
   principals once the read surface is promoted.

## TDD sequence

1. Add red tests for mandatory checker construction, default-denied unknown
   sources, and the raw-reader inventory.
2. Remove the nullable checker and unfiltered SQL read path.
3. Change the entity checker to deny every source it cannot prove through an
   entity policy.
4. Update existing provider tests to supply an explicit test-only allow-all
   checker where they are testing FTS behavior rather than access policy.
5. Update the README and changelog, then run focused tests, package/layer gates,
   the split suites, and `composer verify`.

## Deferred to #2192

This work does not promote the read API. #2192 owns the explicit immutable
principal parameter, source-resolver registry, principal-safe entity
re-projection, safe query/filter/facet/rank recomputation, and pagination-oracle
coverage. Until that lands, the provider and its result DTOs remain `@internal`.
