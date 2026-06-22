# Groups extraction + per-bundle field activation

**Date:** 2026-04-18
**Status:** Implemented 2026-04-18 → 2026-04-19. Framework side landed as a 10-commit series on `main` (see §Commit history); Minoo adoption pending as a separate PR.
**Lead:** Russell Jones.
**Issue:** [#1296](https://github.com/waaseyaa/framework/issues/1296) (framework extraction), TBD (Minoo adoption).

## Context

Waaseyaa hosts two consumer products:

- **Minoo** — community-facing platform with a first-class Business concept modeled as `Group(type='business')`. A single `Group` entity with a bundle discriminator; ownership, media, feed, and consent are all wired around it.
- **Miikana** (not yet built against this extraction) — proposal/funding-workflow product. Miikana is about to add tenancy, where a tenant is a logical "organization." A Miikana tenant and a Minoo Business represent the same real-world thing: an Indigenous-led entity that shows up in more than one surface.

Letting Miikana introduce its own `Organization` entity would produce two separate rows for the same real-world entity, with no shared identity or model. Sync would become a human problem.

**Decision (pre-extraction, not revisited here).** Miikana tenants and Minoo Businesses are two bundles of the same underlying `Group` entity — `Group(type='organization')` and `Group(type='business')`. Both are consumed from a shared framework package, `waaseyaa/groups`. This is the clean long-term path, chosen deliberately over the fast path of a Miikana-owned Organization with a later refactor to shared Group.

## Scope

Two feature branches, both named `groups/extract`; one PR per repo. PRs are **not merged** by the implementer — Russell reviews.

### Framework PR — branch `groups/extract`

1. **Specs.** [`docs/specs/bundle-scoped-storage.md`](../specs/bundle-scoped-storage.md) and [`docs/specs/bundle-scoped-fields.md`](../specs/bundle-scoped-fields.md) land first as separate commits. Cross-referenced from `entity-system.md`, `infrastructure.md`, `access-control.md`, `field-access.md`, `operator-diagnostics.md`.
2. **Framework activation.** `FieldDefinition::$targetBundle` and `EntityType::$bundleEntityType` are dormant today — declared on interfaces, unconsulted by resolvers. This PR makes them live in `waaseyaa/field`, `waaseyaa/entity`, `waaseyaa/entity-storage`, `waaseyaa/access`, and the `waaseyaa/foundation` diagnostic layer. Touches field registration, entity field resolution, SQL schema handling, SQL query routing, access policy dispatch, and schema drift checking. The specs carry the concrete contract.
3. **`waaseyaa/groups` package.** A new Layer 3 content-type package containing `Group` (minimal core), `GroupType` (bundle config entity), and `GroupsServiceProvider`. Ships no pre-registered bundles — products register their own. Tests prove standalone consumption (package functions with no product present) and two-bundle coexistence with disjoint fields (activates the mechanism end-to-end).
4. **`extraction-log.md` update.** `node`, `taxonomy`, `media` are flagged as future candidates for per-bundle adoption; no migration in this PR.

### Minoo PR — branch `groups/extract`, depends on framework PR

Adopts the new framework shape. Minoo's 20 Business fields currently declared on its core Group EntityType move to a bundle-scoped registration on Minoo's `business` GroupType; the existing flat `group` table splits into a minimal base table plus a `group__business` subtable.

## Explicitly out of scope

- **Miikana.** Read-only reference. No Miikana source, composer, or test changes.
- **`waaseyaa/communities`, `waaseyaa/consent-kit`, `waaseyaa/identity` extractions.** Community and consent stay in Minoo; user/ResourcePerson consolidation is a separate effort.
- **Changes to Minoo's public routes, URLs, rendered HTML, API response shapes, UI copy, or behavior.** Byte-equivalence on Business surfaces is an acceptance gate.
- **Migration of existing multi-bundle framework entities** (`node`, `taxonomy`, `media`). Flagged in `extraction-log.md`; no changes here.

## Decisions summary

### A. Core Group stays genuinely minimal

Core `group` table owns: `gid`, `uuid`, `type` (bundle key), `name` (label), `langcode`, `_data`. No Business-specific columns. No `status`, no `community_id`, no `consent_public` on core — these are Business-view concerns that don't generalize to a Miikana Organization.

Timestamp columns (`created`, `changed`) are a product-level concern, not part of this extraction's base-table shape. Products that need them declare the columns via `fieldDefinitions` following the Node precedent; absent a schema promotion, the values land in the `_data` JSON blob via `SqlEntityStorage::splitForStorage()`. Promoting them to base-table columns is an additive, product-driven migration — not a framework requirement.

Promoting any bundle-specific field to core on "cross-bundle primitive" grounds was explicitly rejected in brainstorming: without a spec-blessed criterion for promotion, each future field becomes a judgment call under time pressure, and core rots.

### B. Per-bundle storage via subtables

The framework specs were silent on bundle-scoped storage prior to this work; both contract specs now land in [`bundle-scoped-storage.md`](../specs/bundle-scoped-storage.md) and [`bundle-scoped-fields.md`](../specs/bundle-scoped-fields.md). Every multi-bundle entity today (`node`, `taxonomy`, `media`) still uses flat-table storage with all fields on the base table — per-bundle migration is a separate, opt-in choice for each of those packages (see [`../specs/extraction-log.md`](../specs/extraction-log.md) "Future adoption candidates"). Among the candidate patterns for activation:

- **Nullable union columns on the base table.** Rejected — re-couples the core table shape to Business concerns and bloats progressively as new bundles land.
- **`_data` JSON blob for bundle-specific values.** Rejected — defeats queryability and indexing; no mature framework treats this as primary storage.
- **Per-bundle subtables with FK to base.** Selected. Normalized; each new bundle lands additively without altering existing tables; indexing and WHERE clauses behave as they do on any other table.

The canonical contract is spec'd in [`bundle-scoped-storage.md`](../specs/bundle-scoped-storage.md).

### C. Framework activation is the first-class use case, not a local hack

Because `waaseyaa/groups` is the first package in the monorepo to light up `targetBundle`, the framework-side changes are not narrowly scoped to Group — they are the canonical activation path that future adopters (`node`, `taxonomy`, `media`, and external extensions) will follow. Three general-use mechanisms landed:

- **Field registration at the bundle level.** `EntityTypeManager::addBundleFields($entityTypeId, $bundle, FieldDefinition[])` with collision enforcement.
- **Query JOIN routing.** `SqlEntityQuery` reads `FieldDefinition::targetBundle` and injects INNER JOINs through subtables automatically. Cross-bundle field-name ambiguity throws `BundleAmbiguousFieldException` — locked as a framework contract; no silent bundle selection.
- **Access policy bundle scoping.** `#[AccessPolicy]` gains an optional `bundles:` array parameter. Empty default preserves semantics of every existing policy; migration-free rollout.

See [`bundle-scoped-fields.md`](../specs/bundle-scoped-fields.md) for the full contract.

### D. Ambiguity policy locked as contract

`SqlEntityQuery` throws on cross-bundle field-name ambiguity — when a field name exists in multiple bundles and the query does not constrain the bundle. Locking this rule at spec level prevents the class of bug where a future multi-bundle consumer silently reads data from the wrong bundle because the framework picked one for them.

### E. Two-query load path

Load resolves the bundle from the base row first, then dispatches a single PK lookup against the matching subtable. Simpler than a single SQL with N-way LEFT JOINs; both queries hit primary keys; the second query is elided for entities whose bundle has no registered fields.

### F. Existing multi-bundle entities remain untouched

`node`, `taxonomy`, `media` continue to store all fields on their base tables. Activation is additive: entities without `targetBundle`-scoped fields retain exactly current behavior. The framework test suite proves this — `StandaloneConsumptionTest::noSubtablesMaterializedWhenZeroBundlesRegistered` asserts that a fresh install with zero registered bundles produces zero `group__*` subtables, and the existing `SqlSchemaHandler`/`SqlEntityStorage` tests for non-bundle-scoped entity types continue to pass untouched.

### G. Storage shape (as built)

Three details of the framework-derived schema diverge from the pre-implementation sketch in §A; they are noted here rather than silently reshaping the doc so that anyone literal-diffing `PRAGMA table_info("group")` output against this design has a one-stop reconciliation.

- **No `created` / `changed` base columns.** `SqlSchemaHandler::buildTableSpec()` does not emit timestamp columns for the base table. Products that need them follow the Node precedent (declare via `fieldDefinitions`, land in `_data` by default, promote to a column via `fieldDefinitions` schema override if indexed access matters). Rationale captured in §A.
- **`DEFAULT ''` on NOT NULL string key columns.** `uuid`, `type`, `name`, `langcode` all get `DEFAULT ''` from `SqlSchemaHandler`. This is a framework convention for tolerance to partial writes during schema-build ordering — not a spec-level requirement of this extraction. Values are always populated by `SqlEntityStorage` on insert; the default is defensive, not semantic.
- **`UNIQUE` on `uuid` is a named index, not an inline column modifier.** The schema produces a separate `group_uuid` UNIQUE index rather than `uuid VARCHAR(128) UNIQUE NOT NULL`. Semantically identical for the uniqueness guarantee; surfaces differently in `PRAGMA table_info` (where the column shows no `UNIQUE` flag) versus `PRAGMA index_list` (where `group_uuid` appears).

Subtable shape follows the same framework-convention rules: `{base_table}__{bundle}` with `{id_key}` as PK + FK → base `(ON DELETE CASCADE)`, and bundle-scoped field columns derived via `SqlSchemaHandler::deriveColumnSpec()` (currently private; promotion-to-shared flagged in [`../specs/extraction-log.md`](../specs/extraction-log.md) follow-ups).

## Package layout

```
packages/groups/
├── composer.json                          # waaseyaa/groups
├── phpunit.xml
├── README.md
├── src/
│   ├── Group.php                          # extends ContentEntityBase; id=gid, bundle=type, label=name
│   ├── GroupType.php                      # ConfigEntity
│   └── GroupsServiceProvider.php          # registers Group + GroupType; no pre-registered bundles
└── tests/
    ├── Unit/
    └── Integration/
        ├── StandaloneConsumptionTest.php
        └── TwoBundleCoexistenceTest.php
```

- Composer name `waaseyaa/groups`, namespace `Waaseyaa\Groups\`, PSR-4, `declare(strict_types=1)`, `final class` by default.
- Root framework `composer.json` adds `{"type":"path","url":"packages/groups"}` and `"waaseyaa/groups":"@dev"`.
- Layer 3 (Content Types).

### Framework files changed

- `packages/field/src/FieldDefinitionRegistry.php` — **new.** Keys fields by `(entityTypeId, targetBundle)`; exposes `coreFieldsFor()`, `bundleFieldsFor()`.
- `packages/entity/src/EntityTypeManager.php` — `addBundleFields(string, string, FieldDefinition[]): void`; collision enforcement.
- `packages/entity-storage/src/SqlSchemaHandler.php` — `ensureBundleSubtable()`; bundle loop in `ensureTable()`.
- `packages/entity-storage/src/SqlEntityStorage.php` — `splitForStorage()`, `mapRowToEntity()` with subtable merge, transactional save.
- `packages/entity-storage/src/SqlEntityQuery.php` — condition routing, INNER JOIN injection, `BundleAmbiguousFieldException`.
- `packages/access/src/AccessPolicyAttribute.php` — optional `array $bundles = []`.
- `packages/access/src/EntityAccessHandler.php` — bundle filter during policy iteration.
- `packages/foundation/src/Diagnostic/HealthChecker.php` — subtable-aware schema drift.

## Minoo migration plan

### Pre-flight

- Branch `groups/extract` on `/home/fsd42/dev/minoo`. Confirm `vendor/bin/phpunit` green on the branch base.
- Capture HTTP byte-equivalence baselines for four surfaces:
  - `GET /businesses` (index)
  - One `GET /communities/{slug}` page that lists businesses
  - One `GET /businesses/{slug}` with `consent_public=1`
  - One `GET /businesses/{slug}` with `consent_public=0`
- Normalize baselines via a deterministic diff script against known environmental noise (timestamps, per-boot UUIDs).

### Schema migration

`migrations/YYYYMMDD_HHMMSS_split_group_into_business_subtable.php`:

1. Create `group__business` with FK to `group(gid) ON DELETE CASCADE` and indexes on queryable fields (`community_id`, `consent_public`).
2. Copy existing Business data:
   `INSERT INTO group__business (gid, community_id, consent_public, address, ...) SELECT gid, community_id, consent_public, address, ... FROM "group" WHERE type='business'`.
3. Drop the moved columns from `group`. SQLite requires the table-recreate pattern via `SchemaBuilder`; Postgres/MySQL uses `ALTER TABLE DROP COLUMN` per field.

**Forward-only accepted.** SQLite cannot cleanly revert this migration (table recreation, not ALTER). Minoo's development databases are treated as fresh-install surfaces; production rollback is a full restore, not a down-migration. Documented and accepted.

### ServiceProvider refactor

`src/Provider/AppServiceProvider.php`:

- Delete `src/Entity/Group.php`; replace imports with `Waaseyaa\Groups\Group`.
- Delete the ~140-line `new EntityType(id: 'group', ..., fieldDefinitions: [...20...])` block.
- Register `business` as a GroupType.
- Add 20 `new FieldDefinition(..., targetBundle: 'business')` declarations via `$this->addBundleFields('group', 'business', [...])`.

### Controllers, templates, tests unchanged

`BusinessController::list()` and `::show()` continue to call the same `condition(...)` chain; `SqlEntityQuery` routes the bundle-scoped conditions through the new subtable JOIN automatically. `BusinessControllerConsentTest` passes unchanged. `ResourcePerson`'s `linked_group_id` FK still points to `group.gid`.

### Open verification task

`BusinessController::show()` queries by slug, but the initial exploration of Minoo's code did not enumerate `slug` among the 20 declared Business fields. During the Minoo migration's first step, confirm whether slug is:

- (a) a 21st field missed in the initial survey,
- (b) provided by `waaseyaa/path` as an alias entity, or
- (c) derived from `name`.

Adjust the moved-columns list accordingly. Resolve by reading Minoo's current `AppServiceProvider` during implementation, not speculatively in this design. This is a Minoo-internal verification; it does not affect framework shape.

### Acceptance gates (non-negotiable)

1. Framework tests prove bundle-scoped field resolution works in isolation — a two-bundle fixture entity with disjoint per-bundle fields stores, loads, queries, and renders correctly.
2. `waaseyaa/groups` package tests pass with no product present.
3. Minoo's full test suite passes post-refactor, including `BusinessControllerConsentTest` unchanged.
4. Captured HTTP baselines match byte-for-byte post-refactor, modulo clearly-environmental noise (documented).
5. Fresh Minoo checkout executes `composer install → migrate → seed → boot → serve /businesses` cold.
6. A hypothetical second GroupType (test-only fixture) with disjoint fields coexists with `business` in the same database without schema collision.

## Commit structure

### Framework PR

Planned: branch `groups/extract`; landed on `main` as a 10-commit series (with three interleaved spec-clarification commits surfaced during implementation — see §Commit history). Title for the review-facing summary: `feat: waaseyaa/groups extraction + per-bundle field activation`.

Each commit built and tested green in isolation. Each commit message references the relevant design decision above.

1. `docs(specs): add bundle-scoped-storage and bundle-scoped-fields` — specs + cross-references only.
2. `feat(field): FieldDefinitionRegistry with core/bundle partitioning` — registry + `EntityTypeManager::addBundleFields()` + collision tests. No storage wiring.
3. `feat(entity-storage): ensureBundleSubtable + bundle loop in SqlSchemaHandler` — subtable materialization + `ensureTable()` bundle loop. Fixture-entity tests.
4. `feat(entity-storage): activate per-bundle save/load path` — `splitForStorage`, `mapRowToEntity` with subtable merge, transactional save. Round-trip tests.
5. `feat(entity-storage): bundle-scoped query routing in SqlEntityQuery` — condition routing + INNER JOIN + `BundleAmbiguousFieldException`. Filter + ambiguity tests.
6. `feat(access): bundle-scoped policy attribute` — `bundles:` param + handler filter. Backward-compatibility tests.
7. `feat(operator-diagnostics): subtable-aware schema drift` — drift algorithm + health-checker tests.
8. `feat(groups): extract waaseyaa/groups package with Group + GroupType` — Group, GroupType, provider, `StandaloneConsumptionTest`, `TwoBundleCoexistenceTest`. Root composer wiring.
9. `docs(extraction-log): flag node, taxonomy, media for per-bundle adoption` — plus framework follow-ups captured.
10. `docs(plans): 2026-04-18 groups-extraction design` — this document, finalized.

### Minoo PR

Branch: `groups/extract`. Title: `feat: adopt waaseyaa/groups with per-bundle business fields`.

PR body explicitly declares: `Depends on waaseyaa/waaseyaa PR #X — review framework PR first.`

1. `test(business): capture public-surface byte-equivalence baselines`.
2. `chore(composer): add waaseyaa/groups dependency`.
3. `feat(migrations): split group table into core + group__business subtable`.
4. `refactor(app): adopt waaseyaa/groups; move business fields to GroupType registration`.
5. `test(business): re-verify byte-equivalence; document residual diffs`.
6. `docs(plans): groups-adoption-minoo` — cross-linked to this document.

## Commit history

Framework series landed 2026-04-18 → 2026-04-19, rebased onto `c1582c5f` (PR #1293, `/token endpoint`) before PR open. SHAs below are the post-rebase, PR-facing SHAs on branch `groups/extract`; newest last:

| # | SHA | Subject |
|---|---|---|
| 1 | `f7304222` | `docs(specs): add bundle-scoped-storage and bundle-scoped-fields` |
| — | `de265149` | `docs(specs): clarify FieldDefinitionRegistry interface location and normalization boundary` (spec clarification surfaced during commit 2) |
| 2 | `6c87e567` | `feat(field): FieldDefinitionRegistry with core/bundle partitioning` |
| 3 | `25f7be72` | `feat(entity-storage): ensureBundleSubtable + bundle loop in SqlSchemaHandler` |
| — | `2642a390` | `docs(specs): note FK schema extension and record extraction follow-ups` (spec clarification surfaced during commit 3) |
| 4 | `188d2f3f` | `feat(entity-storage): activate per-bundle save/load path` |
| 5 | `6b0bcbdd` | `feat(entity-storage): bundle-scoped query routing in SqlEntityQuery` |
| — | `eb742ae5` | `docs(specs): note ContentEntityBase registry-fallback invariant` (spec clarification surfaced during commit 5) |
| 6 | `d6a280f6` | `feat(access): bundle-scoped policy attribute` |
| 7 | `6103228f` | `feat(operator-diagnostics): subtable-aware schema drift` |
| 8 | `a310cc3f` | `feat(groups): extract waaseyaa/groups package with Group + GroupType` |
| 9 | `dec691df` | `docs(extraction-log): flag node, taxonomy, media for per-bundle adoption` |
| 10 | *(this commit)* | `docs(plans): 2026-04-18 groups-extraction design` |

The three interleaved `docs(specs)` commits are spec clarifications whose need surfaced during implementation; they are co-sequenced with the implementation commits rather than back-folded into commit 1 so the revision history honestly reflects when each invariant was discovered.

## Open questions — downstream of this extraction

Not decided in this work. Each could lock Miikana's consumption shape prematurely.

1. **Per-person cross-product identity.** If a real person is both a Miikana tenant user and a Minoo Business owner, how are Miikana's `User` and Minoo's `ResourcePerson` related? Shared id, shared uuid, external identity, or none? This PR preserves Minoo's `ResourcePerson → Group` linkage untouched. Miikana integration decides.
2. **Cross-product Group visibility.** A `type='organization'` Group is invisible to Minoo's `/businesses` route because the route hard-filters `type='business'`. If a use case later requires cross-product visibility — showing a Miikana-registered Organization on Minoo's Business surface once the community approves — the mechanism is not predetermined. Bundle promotion, dual-bundle membership (not currently supported), or a separate visibility flag are all candidates. This PR makes no cross-visibility promise.
3. **Per-bundle API resource shapes.** `SchemaController` and GraphQL currently emit one merged shape per EntityType. Miikana may eventually need `/schema/group/organization` — a per-bundle resource shape following the Drupal JSON:API precedent. Deferred.

## Follow-ups (flagged, not open decisions)

- **`scaffold:bundle` CLI** (referenced in `operations-playbooks.md`) predates this activation. May need to emit per-bundle field registrations. Out of scope for this extraction.
- **Node, taxonomy, media adoption.** Tracked in [`../specs/extraction-log.md`](../specs/extraction-log.md).

## References

- Contract specs: [`../specs/bundle-scoped-storage.md`](../specs/bundle-scoped-storage.md), [`../specs/bundle-scoped-fields.md`](../specs/bundle-scoped-fields.md).
- Framework invariants: [`../specs/entity-system.md`](../specs/entity-system.md), [`../specs/infrastructure.md`](../specs/infrastructure.md), [`../specs/access-control.md`](../specs/access-control.md), [`../specs/field-access.md`](../specs/field-access.md), [`../specs/operator-diagnostics.md`](../specs/operator-diagnostics.md).
- Extraction log: [`../specs/extraction-log.md`](../specs/extraction-log.md).
