<!-- Spec reviewed 2026-06-15 - convention-spec-gate D-10: this v1 mission spec shipped as M-002 and was superseded by the canonical doctrine spec migration-platform.md. Added a SUPERSEDED banner and corrected the stale Draft status so it no longer reads as a live spec. -->
# Migration Platform v1 — Substrate in Core

> **⚠️ SUPERSEDED.** This is the original M-002 mission spec, retained for
> historical/audit context only. The mission **shipped 2026-05-13**; the live,
> canonical doctrine spec is **[`migration-platform.md`](migration-platform.md)** —
> read that for the current Migration Platform surface. Do not build against the
> draft requirements below.

**Status:** Superseded by [`migration-platform.md`](migration-platform.md) — shipped as M-002 (mission `migration-platform-v1-01KRCDE9`, 2026-05-13); retained for historical/audit context.
**Audience:** framework maintainers; input for Spec Kitty `specify` → `plan` → `tasks` flow
**Mission ID:** TBD (to be assigned by `@jonesrussell` on mission creation)
**Origin:** [ADR 012a](../adr/012a-migration-substrate-in-core.md) (Accepted 2026-05-11).

**Governing ADR:** [ADR 012a](../adr/012a-migration-substrate-in-core.md) — substrate in core, source readers as packages, WordPress reader first-party priority.

**Charter linkage:**
- [`stability-charter.md`](stability-charter.md) §10 lists ADR 012a in governing ADRs; this mission delivers the surface that ADR commits to.
- Not a beta-gate dependency. Migration is a user-acquisition lever (see §0); ships anywhere in v0.x / v1.x.

**Sibling missions:**
- [`entity-storage-v2.md`](entity-storage-v2.md) — must ship WP04 (lifecycle events) and WP08 (revisionable storage API) before this mission's WP05 can land. See §13 dependencies.
- `waaseyaa-migrate-source-wordpress` (separate, post-this-mission) — first first-party source-reader package; not in this mission's scope.

---

## 0. Origin

ADR 012a reversed ADR 012's "migration out of scope" verdict under the parity-with-Drupal-12-and-Laravel-14 reframe. The strategic argument: AI-first, modern PHP, and attribute policies do not matter to a WordPress site owner if there is no path *to* Waaseyaa. The framework's mission promise (obsolete Drupal, Laravel, WordPress) is incoherent without a migration story.

WordPress is the largest single user-acquisition lever — 40%+ of the web. A reader for WordPress XML (WXR) is the first first-party source-reader package. This mission ships the substrate the WordPress reader (and every subsequent reader) sits on: plugin contracts, manifest format, CLI runner, idempotency primitives, rollback, conformance test suite.

This mission **does not** ship the WordPress reader. That is the next mission, in a sibling repo or package, taking this mission's accepted-stable substrate as its foundation.

---

## 1. Goals / non-goals

### 1.1 Goals

1. Define the **Source / Process / Destination plugin contract** as stable framework surface.
2. Define the **`MigrationDefinition` manifest format** for declaring migrations.
3. Ship the **default `EntityDestination`** — writes through the entity-storage coordinator (ADR 010), respecting lifecycle events (ADR 011) and revisions (ADR 016).
4. Ship a small library of **essential process plugins** (PassThrough, HtmlSanitize, Lookup, Concat, TypeCoerce) sufficient for non-trivial migrations.
5. Implement the **ID-mapping table** and `SourceId` value object for idempotent re-runs across runs.
6. Implement the **CLI runner** with the `import:*` verb namespace (avoids collision with schema-migration `migrate:*` per ADR 012a).
7. Implement **per-record rollback** via `DestinationPluginInterface::rollback()` and the `import:rollback` CLI.
8. Implement **resume semantics** — interrupted runs continue from where they stopped without re-importing already-imported records.
9. Ship the **conformance test suite** — reusable base classes that any source/destination implementation must pass.
10. Validate the mission with a **reference CSV → entity migration**, demonstrating end-to-end import + resume + rollback.

### 1.2 Non-goals

- **WordPress source reader.** Separate sibling mission; ships as `waaseyaa-migrate-source-wordpress` composer package.
- **Drupal 7 source reader.** Same — separate package, separate mission, second priority.
- **Drupal 10+ source reader.** Same — third priority, later.
- **Admin UI** for migration management. CLI-only in v0.x.
- **Incremental / continuous sync.** Source readers running as ongoing watchers. Out of scope for v0.x.
- **Real-time conflict resolution.** Concurrent source mutation during a migration is operator concern, not framework concern.
- **Drupal-style migrate UI** (Migrate Tools admin views). Future ADR if ever.
- **Content-promotion via migration.** Migrations are inbound (foreign → Waaseyaa). Content promotion between Waaseyaa environments is a fixture/seed concern, not a migration concern.

---

## 2. Scope summary

### 2.1 In scope

- `SourcePluginInterface`, `ProcessPluginInterface`, `DestinationPluginInterface` and their registration mechanism (`HasMigrationPluginsInterface` provider capability).
- Reserved plugin-id namespace for first-party process plugins.
- `MigrationDefinition` value object; manifest discovery via `HasMigrationsInterface` provider capability.
- Dependency-graph computation with cycle detection.
- `EntityDestination` — writes via storage coordinator, fires lifecycle events, creates initial revisions on revisionable types.
- Process plugins: `PassThrough`, `HtmlSanitizeProcessor`, `LookupProcessor`, `ConcatProcessor`, `TypeCoerceProcessor`, `DefaultValueProcessor`.
- `migration_id_map` table schema; `SourceId` value object; stable-ID hashing.
- CLI commands: `import:run`, `import:run-all`, `import:status`, `import:rollback`, `import:reset`, `import:resume` (six commands).
- Progress persistence — per-record state for resume.
- Per-record rollback.
- Error model — typed exceptions on stable surface; configurable halt-on-error vs continue-on-error.
- Conformance test suite (`SourceConformanceTestCase`, `DestinationConformanceTestCase`).
- Reference CSV source reader (test fixture only; not a first-party package).
- Streaming source semantics — sources return iterables, not arrays. Memory-bounded.
- Concurrency guard — filesystem lock prevents two simultaneous runs of the same migration.

### 2.2 Out of scope

(See §1.2 non-goals.)

---

## 3. Functional requirements

Normative requirements use **MUST / SHOULD / MAY** per RFC 2119. Numbered for Spec Kitty tokenization.

### 3.1 Plugin contracts

- **FR-001** The framework MUST expose `Waaseyaa\Migration\Plugin\SourcePluginInterface` as stable surface.
- **FR-002** `SourcePluginInterface` MUST require: `records(): iterable` (yielding `SourceRecord` values), `sourceIdFor(SourceRecord): SourceId`, `count(): ?int` (nullable when the source can't pre-compute total).
- **FR-003** The framework MUST expose `Waaseyaa\Migration\Plugin\ProcessPluginInterface` as stable surface.
- **FR-004** `ProcessPluginInterface` MUST require: `transform(mixed $value, ProcessContext): mixed`. ProcessContext carries the full source record, the migration definition, and a lookup callable for ID-map queries.
- **FR-005** The framework MUST expose `Waaseyaa\Migration\Plugin\DestinationPluginInterface` as stable surface.
- **FR-006** `DestinationPluginInterface` MUST require: `write(DestinationRecord): WriteResult`, `rollback(WriteResult): void`, `lookup(SourceId): ?WriteResult`.
- **FR-007** Plugins MUST be registered via the `HasMigrationPluginsInterface` provider capability (parallel to `HasNativeCommandsInterface`).
- **FR-008** Plugin-id collisions MUST fail at boot with a typed `MigrationPluginCollisionException` carrying both registering FQCNs.
- **FR-009** Each plugin class MUST declare an id via `id(): string` and a stability via `stability(): 'stable'|'experimental'`. Experimental plugins emit a `framework.deprecation` notice on first use per process.
- **FR-010** Process plugin chains MUST be supported — multiple processors run on one destination field in array order declared by the manifest.

### 3.2 Manifest format

- **FR-011** `Waaseyaa\Migration\MigrationDefinition` MUST be a stable surface value object.
- **FR-012** `MigrationDefinition` MUST declare: id (string), source (SourcePluginInterface instance), process (array<string, ProcessPluginInterface|string>), destination (DestinationPluginInterface instance), dependencies (string[]).
- **FR-013** Migrations MUST be discovered via the `HasMigrationsInterface` provider capability OR via a filesystem path declared in `config/waaseyaa.php` `migration.manifest_paths` (string[]).
- **FR-014** Dependency declarations MUST be validated at registration. Missing dependencies raise `MigrationDependencyMissingException` carrying the missing id.
- **FR-015** Cycle detection MUST run at registration. Cycles raise `MigrationCycleException` carrying the cycle path.
- **FR-016** Process maps MUST be field-keyed — the key is the destination field name, the value is either a `ProcessPluginInterface` instance (or array thereof for chains) or a string that resolves to `PassThrough` on the named source field.
- **FR-017** Migration ids MUST be globally unique within an app. Collision raises `MigrationPluginCollisionException` (reusing the plugin-collision exception; id namespaces overlap).

### 3.3 EntityDestination

- **FR-018** The framework MUST ship `EntityDestination` as the default `DestinationPluginInterface` implementation.
- **FR-019** `EntityDestination::write()` MUST write through the entity-storage coordinator (ADR 010). Multi-backend entity types (e.g. a `dictionary_entry` with a vector field) MUST migrate correctly — the coordinator fans out per backend during the import write.
- **FR-020** `EntityDestination` MUST check the `create` access operation on the target entity type before writing. Denials emit on the `entity.lifecycle` log channel and raise `DestinationWriteException`.
- **FR-021** `EntityDestination::write()` MUST cause the storage coordinator to dispatch `BeforeSaveEvent` and `AfterSaveEvent` (ADR 011) per saved entity.
- **FR-022** A `SaveContext::isImport()` flag MUST be available to subscribers. Migration-aware subscribers detect imports and act differently (e.g. skip cache invalidation during bulk import); non-aware subscribers see imports as regular saves.
- **FR-023** For revisionable entity types (ADR 016), `EntityDestination` MUST create an initial revision on first import per `SourceId`. Re-runs MUST NOT create new revisions unless field values changed.
- **FR-024** `EntityDestination` MUST accept a target bundle in the `MigrationDefinition`'s destination configuration. The bundle resolves at write time, not at registration.

### 3.4 ID mapping

- **FR-025** The framework MUST ship a `migration_id_map` table on stable surface. Schema: `(migration_id TEXT, source_id_hash TEXT, destination_entity_type TEXT, destination_uuid TEXT, last_imported_at TEXT, last_run_id TEXT, source_record_hash TEXT, PRIMARY KEY (migration_id, source_id_hash))`.
- **FR-026** `Waaseyaa\Migration\SourceId` MUST be a stable surface value object. Carries the source identifier value(s) and the source type.
- **FR-027** `SourceId` hashing MUST be deterministic. Re-running a migration with the same source data MUST produce identical hashes.
- **FR-028** The framework MUST expose a `MigrationIdMap::lookupDestination(string $migrationId, SourceId $sourceId): ?WriteResult` API on stable surface.
- **FR-029** `EntityDestination::write()` MUST update the id-map after each successful write (atomic with the entity write through the same transaction).
- **FR-030** Re-runs of an already-imported source record MUST be idempotent — no duplicate entities created, no errors raised.
- **FR-031** Re-runs MUST update the destination entity if the `source_record_hash` differs from the prior run's hash. Unchanged source records skip the write path entirely.

### 3.5 CLI runner

- **FR-032** `bin/waaseyaa import:run <migration-id>` MUST execute a single migration end-to-end. Exit code: 0 on full success, 1 on any error.
- **FR-033** `bin/waaseyaa import:run-all` MUST execute all registered migrations in dependency order from the DAG (FR-015).
- **FR-034** `bin/waaseyaa import:status` MUST output per-migration state: pending / running / partial / complete / failed, plus record counts (total / imported / failed / skipped).
- **FR-035** `bin/waaseyaa import:rollback <migration-id>` MUST undo a migration via per-record rollback (FR-041).
- **FR-036** `bin/waaseyaa import:reset <migration-id>` MUST clear the id-map for a migration. Does NOT delete destination entities; re-running after reset re-imports them as new entities.
- **FR-037** `bin/waaseyaa import:resume <migration-id>` MUST continue an interrupted run from the last-recorded record position.
- **FR-038** Per-record progress MUST persist to a `migration_run_state` table after every record (or in batched commits of ≤ 100 records). Resume reads this table.
- **FR-039** `--dry-run` flag on `import:run` and `import:run-all` MUST execute the source and process steps but skip destination writes. Output: record counts and any errors that would have occurred.
- **FR-040** `--limit=<N>` flag MUST limit the run to the first N records. Useful for sampling.

### 3.6 Rollback

- **FR-041** `DestinationPluginInterface::rollback(WriteResult)` MUST undo a single record's write.
- **FR-042** `EntityDestination::rollback()` MUST delete the destination entity, respecting access policies (`delete` operation on entity type) and lifecycle events (`BeforeDeleteEvent` / `AfterDeleteEvent` fire normally).
- **FR-043** `import:rollback <migration-id>` MUST walk the id-map in reverse last-imported order — `last_imported_at DESC`, tie-broken by `last_run_id DESC` for sub-second determinism — and call rollback per record. (The stable-surface `migration_id_map` table (FR-025) carries no immutable creation-order column; `last_imported_at` is the only ordering signal and is refreshed by `upsert()` on every re-import. Best-effort rollback (FR-044) deletes the destination entity by `destination_uuid`, which is order-independent for *what* is removed; the ordering is an FK/dependency heuristic, for which most-recently-touched-first is the deterministic, implementable contract.)
- **FR-044** Rollback errors MUST be logged but MUST NOT halt the rollback walk. Best-effort semantics. After completion, `import:status` reflects per-record rollback success/failure.

### 3.7 Error model

- **FR-045** The mission MUST ship these exception types on stable surface: `MigrationCycleException`, `MigrationPluginCollisionException`, `MigrationDependencyMissingException`, `SourceReadException`, `ProcessException`, `DestinationWriteException`, `MigrationAbortedException`. Each carries a stable string `code` field.
- **FR-046** Per-record errors during a run MUST be recorded (in `migration_run_state.error` field) without halting the run by default.
- **FR-047** `--halt-on-error` flag MUST stop the run on first per-record error.
- **FR-048** Run-level errors (id-map corruption, source plugin crash, destination plugin crash) MUST halt regardless of `--halt-on-error`. These are framework-level, not record-level.

### 3.8 Conformance suite

- **FR-049** `Waaseyaa\Migration\Testing\SourceConformanceTestCase` MUST be a reusable base class. Source plugin implementations subclass and pass.
- **FR-050** `Waaseyaa\Migration\Testing\DestinationConformanceTestCase` MUST be a reusable base class. Destination plugin implementations subclass and pass.
- **FR-051** The conformance suite MUST cover: stable-ID hashing semantics, resume-from-partial semantics, error-path handling, streaming-source memory bounds, idempotency on re-run.
- **FR-052** A reference `CsvSource` implementation MUST ship in the framework's test fixtures (NOT as a first-party composer package). Used by the conformance suite and by WP11 validation.

### 3.9 Validation (mission-internal)

- **FR-053** WP11 MUST demonstrate an end-to-end CSV → entity migration using `CsvSource` + `EntityDestination`.
- **FR-054** WP11 MUST demonstrate resume: 1000 records imported, run interrupted at 500, `import:resume` completes the remaining 500, final state shows 1000 successful, 0 duplicates.
- **FR-055** WP11 MUST demonstrate rollback: same migration as above, `import:rollback` removes all 1000 entities, id-map cleared, status returns to "pending."

### 3.10 Concurrency

- **FR-061** Two concurrent `import:run` invocations against the same migration MUST be prevented by filesystem lock. The second invocation raises `MigrationConcurrencyException` with the path to the lock file and the PID holding it.
- **FR-062** The lock MUST be released on process exit (normal or signal-caught). Operators with a stuck lock can manually delete the lock file; the framework MUST document this recovery path.

### 3.11 Documentation

- **FR-056** `docs/specs/migration-platform.md` MUST exist post-mission as the canonical spec for the shipped contract.
- **FR-057** A source-reader-package author guide MUST ship at `docs/extension-authoring/migration-source-readers.md`.
- **FR-058** A process-plugin author guide MUST ship at `docs/extension-authoring/migration-process-plugins.md`.
- **FR-059** An upgrade-guide entry MUST ship for the alpha train that introduces the migration platform (per charter §7).
- **FR-060** A "writing a custom migration" cookbook MUST ship in `docs/cookbook/`.

---

## 4. Stable surface deliverables

Maps the mission's stable-surface output to charter §5 (with proposed §5.8 addition for migration).

| Symbol | Kind | Charter §5.x anchor |
|---|---|---|
| `SourcePluginInterface` | Interface | §5.8 (new) — Migration platform |
| `ProcessPluginInterface` | Interface | Same |
| `DestinationPluginInterface` | Interface | Same |
| `HasMigrationPluginsInterface`, `HasMigrationsInterface` | Provider capabilities | Same |
| `MigrationDefinition` | Value object | Same |
| `EntityDestination` | Concrete (stable) | Same |
| `PassThrough`, `HtmlSanitizeProcessor`, `LookupProcessor`, `ConcatProcessor`, `TypeCoerceProcessor`, `DefaultValueProcessor` | Process plugin classes | Same |
| `SourceId` | Value object | Same |
| `SourceRecord`, `DestinationRecord`, `WriteResult`, `ProcessContext` | Value objects / DTOs | Same |
| `migration_id_map` table schema | Schema | Same |
| `MigrationCycleException`, `MigrationPluginCollisionException`, `MigrationDependencyMissingException`, `SourceReadException`, `ProcessException`, `DestinationWriteException`, `MigrationAbortedException`, `MigrationConcurrencyException` | Exception classes | Same |
| `SaveContext::isImport()` flag | Method | §5.3 — Entity surface (extension) |
| `bin/waaseyaa import:run/run-all/status/rollback/reset/resume` | CLI commands | §5.8 |
| `SourceConformanceTestCase`, `DestinationConformanceTestCase` | Test bases | §5.8 |
| `migration.deprecation` log channel | Channel constant | §4.4 |

**Charter amendment required:** new §5.8 "Migration platform" listing the above as stable surface. Drafted as part of WP12.

---

## 5. Plugin contract spec (normative)

### 5.1 SourcePluginInterface

```php
interface SourcePluginInterface
{
    public function id(): string;
    public function stability(): string;             // 'stable' | 'experimental'
    public function records(): iterable;             // yields SourceRecord
    public function sourceIdFor(SourceRecord $record): SourceId;
    public function count(): ?int;                   // null when unknown
}
```

**Streaming requirement (FR-061a):** `records()` MUST be a generator or other lazy iterable. Implementations MUST NOT eager-load the full source dataset into memory. The conformance suite tests this by importing a fixture larger than a configurable memory budget.

### 5.2 ProcessPluginInterface

```php
interface ProcessPluginInterface
{
    public function id(): string;
    public function stability(): string;
    public function transform(mixed $value, ProcessContext $context): mixed;
}
```

`ProcessContext` carries the full `SourceRecord`, the `MigrationDefinition`, and a lookup callable bound to the `MigrationIdMap` for cross-migration ID resolution.

### 5.3 DestinationPluginInterface

```php
interface DestinationPluginInterface
{
    public function id(): string;
    public function stability(): string;
    public function write(DestinationRecord $record): WriteResult;
    public function rollback(WriteResult $result): void;
    public function lookup(SourceId $sourceId): ?WriteResult;
}
```

`lookup()` is the consult-id-map operation, used by process plugins to resolve cross-migration references (e.g. an imported post's author is a previously-imported user).

### 5.4 Reserved process-plugin id namespace

Reserved ids: `pass_through`, `html_sanitize`, `lookup`, `concat`, `type_coerce`, `default_value`. Owned by the framework. App-defined process plugins MUST use a non-reserved id; recommended convention: `<vendor>_<purpose>` (e.g. `wordpress_shortcode_strip`).

The reservation policy mirrors ADR 010's backend-id namespace policy.

---

## 6. Manifest format spec

### 6.1 MigrationDefinition value object

```php
final readonly class MigrationDefinition
{
    public function __construct(
        public string $id,
        public SourcePluginInterface $source,
        public array $process,                       // array<string, ProcessPluginInterface|string|array<ProcessPluginInterface|string>>
        public DestinationPluginInterface $destination,
        public array $dependencies = [],             // string[]
        public ?string $description = null,
    ) {}
}
```

### 6.2 Process map

The `$process` array's key is the **destination field name**. The value is one of:

- A `ProcessPluginInterface` instance — runs that processor on the source value at this key (the source field name implied by the destination field name; if different, use shorthand string).
- A string — interpreted as a source field name; `PassThrough` runs on that source field.
- An array of `ProcessPluginInterface` instances — processors chain in array order; output of N is input to N+1.

Example:

```php
'process' => [
    'title' => 'post_title',                                          // PassThrough on post_title
    'body' => new HtmlSanitizeProcessor('post_content'),              // sanitize post_content
    'author_id' => new LookupProcessor(                               // resolve via id-map
        sourceField: 'post_author',
        migration: 'wp_users_to_accounts',
    ),
    'slug' => [                                                       // chain
        new ConcatProcessor(['post_slug', '-archive']),
        new TypeCoerceProcessor('string'),
    ],
],
```

### 6.3 Discovery

Migrations are discovered via two mechanisms (FR-013):

1. **Provider capability** — service providers implementing `HasMigrationsInterface` return `MigrationDefinition[]` from a `migrations()` method. Used by source-reader packages.
2. **Filesystem path** — `config/waaseyaa.php` declares `migration.manifest_paths`; each path is scanned for PHP files returning `MigrationDefinition` instances. Used by apps for one-off migrations not packaged.

Both mechanisms register into the same global registry.

---

## 7. EntityDestination spec

### 7.1 Construction

```php
new EntityDestination(
    entityType: 'teaching',
    bundle: 'wordpress_import',
    langcode: 'en',
)
```

### 7.2 Write path

1. Resolve the entity type from the registry.
2. Check access: `Gate::denies('create', $entityType, $account)` — if denied, raise `DestinationWriteException`.
3. Compute the destination entity from the `DestinationRecord` fields.
4. Call `EntityStorage::create($entityType, $values)` and then `save($entity)`.
5. The storage coordinator dispatches `BeforeSaveEvent` and `AfterSaveEvent` (ADR 011).
6. Update the id-map (`migration_id_map`) with `(migration_id, source_id_hash, entity_type, entity_uuid)`.
7. Return a `WriteResult` carrying entity uuid and the source-record hash.

### 7.3 Re-run path (idempotent)

1. On migration run, before writing, call `MigrationIdMap::lookupDestination($migrationId, $sourceId)`.
2. If exists: compute source-record hash. If unchanged: skip the write entirely. If changed: load entity, update fields, save (creates a new revision for revisionable types per FR-023).
3. If not exists: standard write path.

### 7.4 Rollback path

1. Look up the entity via id-map.
2. Check access: `Gate::denies('delete', $entity, $account)` — if denied, log and skip.
3. Call `EntityStorage::delete([$entity])`. Lifecycle events fire normally.
4. Remove the id-map entry.

### 7.5 Import flag

Subscribers detect imports via `SaveContext::isImport(): bool`. The coordinator passes a `SaveContext` to every save; for import-driven saves the `isImport()` flag is true. Subscribers wanting to skip work during bulk imports (e.g. expensive cache invalidation) opt out per subscriber.

---

## 8. ID-mapping spec

### 8.1 Table schema

```sql
CREATE TABLE migration_id_map (
    migration_id        TEXT NOT NULL,
    source_id_hash      TEXT NOT NULL,
    destination_entity_type TEXT NOT NULL,
    destination_uuid    TEXT NOT NULL,
    last_imported_at    TEXT NOT NULL,
    last_run_id         TEXT NOT NULL,
    source_record_hash  TEXT NOT NULL,
    PRIMARY KEY (migration_id, source_id_hash)
);
CREATE INDEX migration_id_map__entity ON migration_id_map(destination_entity_type, destination_uuid);
```

Both indexes (primary + entity reverse) are stable-surface schema. Future changes follow charter §5.4 (manifest/compiler internals are flexible; this schema is not).

### 8.2 SourceId

```php
final readonly class SourceId
{
    public function __construct(
        public string $sourceType,            // e.g. 'wordpress_post'
        public array $keys,                   // associative array of source key fields
    ) {}

    public function hash(): string;           // sha256 of canonical-form (sourceType + sorted keys)
}
```

`SourceId::hash()` MUST be deterministic and stable across runs. Source plugins declare which source-record fields are "key fields" via `sourceIdFor()`.

### 8.3 source_record_hash

A sha256 of the **canonical form** of the source record's field values (sorted keys, JSON-encoded). Compared at re-run to determine if a record changed since last import.

---

## 9. CLI runner spec

### 9.1 import:run

```
bin/waaseyaa import:run <migration-id> [--dry-run] [--halt-on-error] [--limit=N]
```

Executes a single migration:

1. Acquire filesystem lock (`storage/migration-locks/<migration-id>.lock`).
2. Load `MigrationDefinition` from registry.
3. Iterate `source->records()`.
4. For each record:
   - Compute `SourceId` via `source->sourceIdFor()`.
   - Check id-map for prior import. If unchanged hash: skip.
   - Run process map.
   - Call `destination->write()`.
   - Update `migration_run_state` table with success/error.
5. Release lock.
6. Exit 0 on full success, 1 on any per-record error.

### 9.2 import:status output

```
$ bin/waaseyaa import:status
ID                              STATE       TOTAL  IMPORTED  FAILED  SKIPPED  LAST RUN
wp_users_to_accounts            complete    1500   1500      0       0        2026-05-11 14:22
wp_posts_to_teachings           partial     5000   3217      0       0        2026-05-11 14:35
wp_comments_to_engagement       pending     -      -         -       -        -
```

### 9.3 Concurrency lock

Lock file: `storage/migration-locks/<migration-id>.lock`. Contains the PID. Stale locks (PID not running) are documented for manual recovery; the framework does NOT auto-remove them — accidental concurrent runs would otherwise be silent.

---

## 10. Conformance suite spec

### 10.1 SourceConformanceTestCase

Covers:

- `records()` returns a lazy iterable (memory bound: 50MB while importing a fixture larger than 50MB).
- `sourceIdFor()` is deterministic — same record yields same SourceId.
- Stable-hash semantics — `SourceId::hash()` is stable across multiple invocations.
- `count()` returns a non-negative int or null (no NaN, no negative).

### 10.2 DestinationConformanceTestCase

Covers:

- `write()` returns a `WriteResult` with a populated uuid.
- `write()` is idempotent in conjunction with the id-map (writing the same record twice via the runner produces no duplicates).
- `rollback()` reverses a `write()`. Subsequent `lookup()` returns null.
- `lookup()` returns the prior `WriteResult` for an already-written source-id.
- Access denials raise `DestinationWriteException`.

### 10.3 Reference CsvSource

Test-fixture only (FR-052). NOT a first-party composer package. Lives in the framework repo at `tests/Migration/Fixtures/CsvSource.php`. Its purpose is to exercise the contract and serve as a reference for source-reader-package authors; not for production use.

---

## 11. Work package decomposition

Twelve WPs. Each names its primary FR coverage and dependencies. Some WPs have **external dependencies on `entity-storage-v2.md`** — flagged.

| WP | Title | Primary FRs | Internal deps | External deps |
|---|---|---|---|---|
| **WP01** | Plugin contracts + provider capability + registration | FR-001..FR-010 | — | — |
| **WP02** | MigrationDefinition + discovery + dependency graph | FR-011..FR-017 | WP01 | — |
| **WP03** | Essential process plugins (PassThrough, HtmlSanitize, Lookup, Concat, TypeCoerce, DefaultValue) | FR-010 (chain), §5.4 | WP01 | — |
| **WP04** | ID-mapping table + SourceId + idempotency primitives | FR-025..FR-031 | WP01 | — |
| **WP05** | EntityDestination + storage coordinator integration | FR-018..FR-024 | WP01, WP04 | **entity-storage-v2 WP04 (lifecycle events) + WP08 (revisionable storage API) MUST ship first** |
| **WP06** | CLI runner: import:run + import:run-all + import:status + import:dry-run | FR-032..FR-034, FR-039..FR-040 | WP01, WP02, WP05 | — |
| **WP07** | Resume + progress tracking (migration_run_state + import:resume) | FR-037..FR-038 | WP06, WP04 | — |
| **WP08** | Rollback (DestinationPluginInterface::rollback + import:rollback + import:reset) | FR-035..FR-036, FR-041..FR-044 | WP04, WP05 | — |
| **WP09** | Concurrency lock + MigrationConcurrencyException | FR-061..FR-062 | WP06 | — |
| **WP10** | Conformance suite (Source + Destination test cases) + reference CsvSource fixture | FR-049..FR-052 | WP01, WP05 | — |
| **WP11** | End-to-end validation: CSV → entity with resume + rollback proven | FR-053..FR-055 | WP06, WP07, WP08, WP10 | — |
| **WP12** | Documentation + charter §5.8 amendment + upgrade-guide entry | FR-056..FR-060 + charter §5.8 | WP04, WP06, WP09 | — |

### 11.1 Sequencing diagram

```
                          ┌──────────────────────────────┐
                          │  entity-storage-v2 WP04+WP08 │   (external prerequisite for WP05)
                          └──────────────┬───────────────┘
                                         ▼
WP01 ──┬──► WP02 ─┐
       │          │
       ├──► WP03  │
       │          │
       ├──► WP04 ─┼──► WP05 ──► WP06 ──┬──► WP07
       │          │                    │
       │          │                    ├──► WP09
       │          │                    │
       ├──► WP10 ─┘                    │
       │                               │
       └──────────────────► WP08 ──────┤
                                        │
                          WP06+07+08+10 ─┴──► WP11 (validation)
                                              │
                                              ▼
                                           WP12 (docs)
```

### 11.2 Parallelizable WPs

After WP01: WP02, WP03, WP04, WP10 can run in parallel.

After WP04 + WP05 (which gates on external entity-storage-v2 progress): WP06, WP07, WP08, WP09 can sequence as WP06 → (WP07, WP08, WP09 parallel).

WP11 closes the validation; WP12 closes the mission.

### 11.3 Cross-mission coordination

WP05 cannot start until `entity-storage-v2.md` ships WP04 + WP08. This is the only hard external prerequisite. WPs 01–04, 09, 10 in this mission have no external prerequisites and can start immediately.

The natural execution order is:

1. Start `entity-storage-v2.md`.
2. After entity-storage-v2 WP02 (coordinator scaffold) lands, this mission's WP01 can start in parallel.
3. After entity-storage-v2 WP04 + WP08 land, this mission's WP05 can start.
4. WPs 06–09 of this mission overlap with the later WPs of entity-storage-v2.
5. WPs 11–12 of this mission close after all feature WPs.

Both missions can complete within ~6 months end-to-end if run with reasonable parallelism.

---

## 12. Acceptance criteria

The mission is complete when:

1. All 12 WPs are merged.
2. All FRs in §3 are covered by tests.
3. The conformance test suite is green for the reference `CsvSource` and `EntityDestination`.
4. WP11's end-to-end validation runs green in CI: 1000-record CSV → entity migration with resume + rollback.
5. Charter §5.8 is added covering migration-platform stable surface, with tier labels (`stable`) and mission-status labels (`present`) on `public-surface-map.md` / `public-surface-map.php`.
6. The first concrete upgrade-guide entry exists at `docs/upgrades/waaseyaa-alpha-<X>-to-<Y>.md` per FR-059.
7. Author guides for source-reader packages and process plugins ship at `docs/extension-authoring/migration-*.md`.
8. The substrate is **ready for the WordPress source reader mission to start** — defined as: a fresh git clone, `composer create-project`, registering a hypothetical `waaseyaa-migrate-source-wordpress` package, defines `MigrationDefinition` entries, runs `import:run-all`, completes with no framework changes required.

---

## 13. WordPress migration mission (sibling, post-this-mission)

This mission's acceptance criterion 8 (substrate ready for WordPress reader) is the contract handed off to the WordPress reader's mission. That mission lives in a separate document (TBD) and a separate composer package (`waaseyaa-migrate-source-wordpress`).

WordPress mission scope sketch (informational; not part of this mission's spec):

- WXR (WordPress eXtended RSS) XML parser as a `SourcePluginInterface` implementation.
- Source plugin classes per WP entity type: `WordPressPostSource`, `WordPressUserSource`, `WordPressCommentSource`, `WordPressMediaSource`, `WordPressTaxonomySource`.
- Source-specific process plugins: `WordPressShortcodeStrip`, `WordPressOembedExpand`, `WordPressMediaRewriteUrl`.
- Migration definitions: `wp_users_to_accounts`, `wp_terms_to_taxonomy`, `wp_media_to_entities`, `wp_posts_to_teachings` (and equivalents for other consumer apps).
- Documentation: "Migrating your WordPress site to Waaseyaa" — the marketing-grade walkthrough.

Target ship: within 6 months of this mission completing. Estimated effort: 2–3 months for a mature first cut.

---

## 14. Open questions

Mission-specific, in addition to charter §11 operational items.

1. **Plugin-id namespace policy** — like backend ids (charter §11 Q9), should process-plugin ids have a registration mechanism for app-defined plugins? Recommend: same policy as backend ids — framework reserves `pass_through`, `html_sanitize`, etc.; apps use a non-reserved prefix; collision check at boot.
2. **Idempotency hash strategy** — sha256 of canonical form is the default. Configurable per source? Recommend: source plugin declares "stable key fields" via `sourceIdFor()`; canonical form sorts those fields and hashes. Non-key fields contribute to `source_record_hash` (change-detection) but not to `source_id_hash` (identity).
3. **Process plugin chain ordering** — array order is the v0.x answer (§6.2). Should there be a `Pipeline::after()` / `Pipeline::before()` mechanism for cross-package ordering? Recommend: not in v0.x; revisit if community process plugins need cross-package ordering.
4. **Memory budget** — the conformance suite tests "lazy iteration" by importing a fixture larger than 50MB. Should the budget be configurable per migration? Recommend: yes, via `MigrationDefinition::$memoryBudgetBytes` (default 256MB); runner emits a warning if peak memory exceeds budget by 20%.
5. **Error budget** — what's the "OK number of errors" threshold? Recommend: default `error_rate_warn: 0.01`, `error_rate_halt: 0.10` configurable per migration. `--halt-on-error` overrides to halt-on-1.
6. **WP05 external dependency timing** — entity-storage-v2 WP04 (lifecycle events) and WP08 (revisionable storage API) must ship first. If entity-storage-v2 slips, does this mission ship WP05 with a temporary scaffolded `EntityDestination` that writes directly to the legacy storage path? Recommend: NO. WP05 waits. Better to ship a coherent stack than to ship two storage paths.
7. **`config:*` vs `import:*` vs `migrate:*` CLI namespace** — this mission uses `import:*` per ADR 012a. Charter §11 Q11 names the future-ADR question of consolidation. Confirm: this mission stays on `import:*` and does NOT block on the consolidation ADR.
8. **Migration as a config entity?** — should `MigrationDefinition` be storable as a config entity (per ADR 018) and editable via admin UI in v1.x? Recommend: NO for v0.x; migrations are PHP objects in code. v1.x admin-editable migrations is a future ADR, separate from CMI.

---

## 15. References

- [ADR 012a](../adr/012a-migration-substrate-in-core.md) — governing decision (Accepted 2026-05-11).
- [ADR 010](../adr/010-multi-backend-field-storage.md) — `EntityDestination` rides the storage coordinator.
- [ADR 011](../adr/011-entity-lifecycle-events.md) — import writes fire lifecycle events; `SaveContext::isImport()` flag.
- [ADR 016](../adr/016-revisions-first-class.md) — revisionable entity types receive initial revisions on import.
- [`stability-charter.md`](stability-charter.md) — governing API stability rules; this mission proposes §5.8 amendment.
- [`entity-storage-v2.md`](entity-storage-v2.md) — sibling mission; WP05 external dependency.
- [`drupal-comparison-matrix.md`](drupal-comparison-matrix.md) §1.6, §6.3 — origin of the gap; resolved by ADR 012a.
- [`schema-evolution-v2.md`](schema-evolution-v2.md) — sibling mission spec; style template (with `entity-storage-v2.md`).
- 2026-05-11 framework/app audit (`waaseyaa/minoo/docs/audits/2026-05-11-framework-app-audit.md`) — strategic context.
- Drupal core Migrate API — prior art.
- WordPress WXR specification — relevant to the WordPress sibling mission.

---

## 16. Mission metadata for Spec Kitty

```yaml
mission:
  id: TBD
  title: Migration Platform v1 — Substrate in Core
  status: draft-spec
  governing_adrs: [012a]
  related_adrs: [010, 011, 016]
  charter_dependencies:
    - section: §5 (new §5.8 proposed)
      relation: amends
  external_dependencies:
    - mission: entity-storage-v2
      depends_on_wps: [WP04, WP08]
      gates_wp: WP05
  validation_consumer: framework-internal (reference CsvSource fixture)
  validation_entity_type: migration_test_widget (test fixture)
  work_packages: 12
  parallelizable_after_wp01: true
  estimated_breaking_change_count: 0  # additive surface; no existing migration to migrate
  ships_followup_mission_unblocked: waaseyaa-migrate-source-wordpress
  agent_assignments:
    implementer: sonnet
    reviewer: opus
```
