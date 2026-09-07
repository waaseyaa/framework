# 012a — Migration platform: substrate in core, source readers as packages

**Status:** Accepted (2026-05-11) — supersedes [ADR 012](012-migration-platform-out-of-scope.md)
**Mission:** Stability charter ratification; future implementation mission TBD
**Spec context:** `docs/specs/drupal-comparison-matrix.md` §1.6, §6.3 (reverse direction); parity-with-Drupal-12-and-Laravel-14 reframe.

## Context

ADR 012 placed migration tooling out of scope on a cost-benefit argument: "multi-year mission, framework grows by ~30%, Minoo's `Ingestion` shows app-level ETL works." The reasoning was sound under the original lens, which framed Waaseyaa as a domain-modeling framework whose consumers would arrive already built.

A subsequent strategic reframe — **parity with Drupal 12 and Laravel 14** — reweights the decision in two ways:

1. **Drupal 12 ships Migrate as core.** Under the parity standard "must not ship *less* than Drupal 12 in any area that matters," migration moves from "nice-to-have" to required-for-parity.
2. **User acquisition is upstream of every other framework virtue.** AI-first, modern PHP, attribute policies — none of these matter to a WordPress site owner if there is no path *to* Waaseyaa. The framework's mission promise (obsolete Drupal, Laravel, WordPress) is incoherent without a migration story. WordPress alone is 40%+ of the web; the addressable population of frustrated WP site owners is the single largest user-acquisition lever the framework has.

The original cost concern is still real for a *Drupal-feature-parity* Migrate API. It is not real for a *minimal-viable migration platform*, which is the scope this ADR commits to.

## Options reconsidered

### A. Framework ships full Migrate API + first-party source readers in core

Source / Process / Destination plugins, manifest, runner, rollback, idempotency, and built-in readers for WordPress, Drupal 7, Drupal 10+ all in the framework repo. Maximum mission claim; large surface; framework grows substantially. Rejected — keeps a multi-year cost without the ecosystem benefit.

### B. Framework ships nothing; apps own ETL (the ADR 012 position)

Reversed by this ADR. Loses the parity standard; loses the user-acquisition lever; leaves every consumer to reinvent the same pipeline.

### C. Framework ships substrate; source readers ship as packages (CHOSEN)

Core ships the contract (plugin types, manifest format, CLI runner, idempotency primitives, rollback). Source readers ship as separate composer packages under a `waaseyaa-migrate-source-*` naming convention. First-party packages for high-value sources (WordPress first, Drupal 7 second); community-contributable for everything else.

This is the **shape Drupal actually uses today** — Migrate core ships the plugin types; Migrate Plus and Migrate Source X ship source readers. It is the proven pattern; the cost is bounded by "the contract."

## Decision

The migration platform is **in scope** for the framework. The framework ships the **substrate**; source readers ship as **packages**.

### What the framework ships

**Plugin types (stable surface):**
- `SourcePluginInterface` — reads records from a foreign system.
- `ProcessPluginInterface` — transforms records (field-by-field map, lookups, type coercion, expression).
- `DestinationPluginInterface` — writes records into Waaseyaa entities. The default destination is `EntityDestination` which delegates to `EntityStorage` (ADR 010) and respects access policies, lifecycle events (ADR 011), and revisions (ADR 016).

**Manifest format (stable surface):**

A migration is a PHP class (or YAML, decision below) declaring source, process, destination, and dependencies:

```php
return new MigrationDefinition(
    id: 'wp_posts_to_teachings',
    source: new WordPressPostSource(filePath: 'storage/import/wp-dump.xml'),
    process: [
        'title' => 'post_title',
        'body' => new HtmlSanitizeProcessor('post_content'),
        'community_id' => new LookupProcessor(table: 'community_map', sourceKey: 'post_author'),
    ],
    destination: new EntityDestination(entityType: 'teaching', bundle: 'wordpress_import'),
    dependencies: ['wp_users_to_accounts'],
);
```

PHP-first because it carries type safety; YAML form may follow if demand emerges.

**CLI runner (stable surface):**

To avoid collision with the existing schema-migration CLI (`bin/waaseyaa migrate`), data migration uses the `import` verb:

- `bin/waaseyaa import:run <migration-id>` — execute one migration.
- `bin/waaseyaa import:run-all` — execute the full manifest in dependency order.
- `bin/waaseyaa import:status` — current state per migration (pending / running / partial / complete / failed).
- `bin/waaseyaa import:rollback <migration-id>` — undo a migration.
- `bin/waaseyaa import:reset <migration-id>` — clear the ID-mapping table for a migration (allows re-run from scratch).
- `bin/waaseyaa import:resume <migration-id>` — continue a partial migration after crash or interrupt.

The "Migration Platform" name remains the conceptual public name; only the CLI verb is `import`. A future ADR may rename the existing schema-migration commands to `schema:*` and free up `migrate:*` for data migration; that decision is out of scope here.

**Idempotency primitives (stable surface):**
- **ID mapping table** — `migration_id_map` records `(migration_id, source_id_hash, destination_entity_uuid, last_imported_at)`. Stable surface: the table schema, the lookup API, and the `SourceId` value object for source records. (Corrected 2026-09-07: this ADR originally named a `SourceIdInterface`; the substrate shipped a `final readonly class SourceId` — `packages/migration/src/SourceId.php` — and no such interface was ever created. Third-party substitution of identity semantics is therefore not available in v1; that is the shipped design, not an omission to be implemented.)
- **Stable source IDs** — every source record exposes a `SourceId` value object whose hash is stable across runs. Re-running a migration with the same source data MUST be idempotent (no duplicate entities created).
- **Resume semantics** — `import:run` records progress per record; `import:resume` skips already-imported records.

**Rollback support (stable surface):**

Each `DestinationPluginInterface` MUST implement a `rollback(DestinationRecord)` method that undoes a single record's write. The default `EntityDestination` deletes the entity (respecting access policies). `import:rollback <migration-id>` walks the ID-mapping table in reverse order and calls rollback per record.

Rollback is best-effort, not transactional across the full migration. Operators who need transactional guarantees use database backups.

**Backend-conformance test suite (stable surface):**

`Waaseyaa\Migration\Testing\SourceConformanceTestCase` and `DestinationConformanceTestCase`. Source reader packages subclass and must pass: stable-ID semantics, resume semantics, error-path handling, schema-discovery for a known fixture.

### What the framework does NOT ship

- **No specific source reader.** Not WordPress, not Drupal 7, not anything. Source readers live in packages, including first-party ones.
- **No process-plugin library beyond essentials.** The framework ships a handful (`PassThrough`, `HtmlSanitizeProcessor`, `LookupProcessor`, `ConcatProcessor`, `TypeCoerceProcessor`). Anything more specialized ships in source-reader packages or community packages.
- **No admin UI** for migration management. CLI-only in v0.x.
- **No incremental / continuous sync** in v0.x. Migrations are one-shot operations. Resume semantics handle interrupted runs; continuous source-watching is deferred to a future ADR if demand emerges.
- **No real-time conflict resolution.** If a migration runs against a system whose source data is changing concurrently, the framework offers no conflict-resolution semantics. The user runs migrations against quiescent sources (export-then-import pattern).

### Package conventions

**Naming:** `waaseyaa-migrate-source-<system>`. Reserved namespaces for first-party packages:

- `waaseyaa-migrate-source-wordpress`
- `waaseyaa-migrate-source-drupal7`
- `waaseyaa-migrate-source-drupal10`

Community packages use the same prefix freely; the `waaseyaa-migrate-source-` prefix is not reserved (anyone can publish; reputation and first-partyness are signals to users).

**First-party priority order:**

1. **WordPress (WXR XML)** — first reader. Highest user-acquisition value, well-defined single-format source (WordPress eXtended RSS), 40%+ of the web. Target: ship within 6 months of substrate.
2. **Drupal 7** — second reader. Relational schema parsing harder than WP XML; content-type and field-module variance is substantial. Target: ship within 12 months of substrate.
3. **Drupal 10+** — third reader. Easier than D7 (modern entity API to read from); lower priority because the user pool actively wanting to leave is smaller.

Subsequent readers (Joomla, Ghost, static-site generators, Notion, Airtable) are community-driven unless a user-acquisition case justifies first-party status.

### Cost (rough)

| Tranche | Scope | Estimate |
|---|---|---|
| Substrate (contract + CLI + ID map + rollback + conformance suite) | This ADR's framework mission | 3–6 months |
| WordPress source reader | First-party package | 2–3 months |
| Drupal 7 source reader | First-party package | 4–6 months |
| Drupal 10+ source reader | First-party package | 2–3 months |

Total to "viable platform with WP + D7": ~10–12 months. A Year-1 V1 priority, not a multi-year detour.

### Relationship to other framework surfaces

- **ADR 010 (multi-backend storage):** `EntityDestination` writes through the storage coordinator. Multi-backend entities (e.g. `dictionary_entry` with a vector field) migrate correctly through the same destination — the coordinator fans out per backend during the import write.
- **ADR 011 (lifecycle events):** `BeforeSaveEvent` / `AfterSaveEvent` fire on every imported entity. Migration-aware subscribers detect imports via a `SaveContext::isImport()` flag; non-aware subscribers see imports as regular saves.
- **ADR 016 (revisions):** imports create initial revisions per FR-032 default. Migrations of a revisionable entity type produce one initial revision per source record. Subsequent updates from re-runs do not create new revisions unless field values change.
- **Entity Storage v2 mission (`entity-storage-v2.md`):** `EntityDestination` depends on the storage coordinator. Migration substrate mission MUST land after entity-storage-v2 WP04 (lifecycle events) and WP08 (revision API), but the substrate mission's design work can run in parallel.

## Consequences

- **Framework gains a major new layer.** Net surface growth: ~5 stable interfaces, ~6 stable exception/value-object types, ~6 CLI commands, ID-mapping table schema. All additive.
- **User-acquisition story becomes defensible.** "Migrate your WordPress site to Waaseyaa in one command" — credible marketing once the WordPress reader ships.
- **Charter §3.2 beta entry criterion 9** is unaffected — migration was not in the matrix §3 critical-gap list. Matrix §1.6 and §6.3 update from `❌` to `📋` / planned.
- **The CLI namespace question is named but not resolved.** `import:*` for data migration vs `migrate:*` for schema migration is a workable disambiguation; a future ADR may rename schema commands to `schema:*` and consolidate.
- **First-party source-reader packages create a new release surface.** Each package is independently versioned. Coordinated releases when the substrate breaks; otherwise independent.
- **Community ecosystem becomes possible.** A `waaseyaa-migrate-source-*` package on Packagist is a discoverable contribution path that did not exist before.
- **Continuous / incremental sync is a future-ADR door** — not closed, just not opened in v0.x.

## References

- Matrix: `docs/specs/drupal-comparison-matrix.md` §1.6, §6.3.
- Charter: `docs/specs/stability-charter.md` §10 (cross-refs to be updated to point at this ADR).
- Superseded: [ADR 012](012-migration-platform-out-of-scope.md).
- Related: [ADR 010](010-multi-backend-field-storage.md) (storage coordinator is the destination's substrate), [ADR 011](011-entity-lifecycle-events.md) (import events ride the lifecycle surface), [ADR 016](016-revisions-first-class.md) (revision creation on import).
- Future: implementation mission spec at `docs/specs/migration-platform-v1.md` (TBD); first-party reader packages tracked separately.
- Prior art: Drupal core Migrate API, Drupal contrib Migrate Plus, Drupal Migrate Source CSV.
- Parity reference: Drupal 12 Migrate, Laravel 14 (Eloquent factories + Scout; no migration platform per se — parity here is asymmetric, with Drupal as the higher bar).
