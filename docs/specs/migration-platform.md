<!-- Spec reviewed 2026-05-13 - migration-platform-v1 mission landed -->
<!-- Spec reviewed 2026-07-02 - R3 WP1 HtmlSanitizeProcessor nested-case + CDATA-content-model B-4 fix (§3.5, §16) -->

# Migration Platform

**Status:** Stable (M-002 landed, 2026-05-13).
**Package:** `waaseyaa/migration` (Layer 3 — Services).
**Mission archive:** `kitty-specs/migration-platform-v1-01KRCDE9/`.
**Charter section:** `docs/specs/stability-charter.md` §5.8.
**Origin ADR:** [ADR 012a](../adr/012a-migration-substrate-in-core.md).

---

## 1. Overview

The migration platform is the substrate that lets Waaseyaa applications import
content from external systems (WordPress, Drupal, CSV exports, JSON dumps, ad-hoc
APIs) into the framework's entity storage layer. It ships:

1. **Plugin contracts** (`SourcePluginInterface`, `ProcessPluginInterface`,
   `DestinationPluginInterface`) that third-party packages implement to ingest
   new formats or transform values during a run.
2. **A manifest format** (`MigrationDefinition`) — a single PHP value object
   declaring source, process map, destination, and dependencies.
3. **The default entity destination** (`EntityDestination`) — writes through the
   entity-storage coordinator (ADR 010), respecting lifecycle events
   (ADR 011) and revisions (ADR 016).
4. **A small library of essential process plugins** (`PassThroughProcessor`,
   `HtmlSanitizeProcessor`, `LookupProcessor`, `ConcatProcessor`,
   `TypeCoerceProcessor`, `DefaultValueProcessor`).
5. **A CLI runner** with six commands: `import:run`, `import:run-all`,
   `import:status`, `import:resume`, `import:rollback`, `import:reset`.
6. **An idempotency primitive** — the `migration_id_map` table, keyed by a
   deterministic `SourceId` hash, supports re-runs, change detection, and
   rollback.
7. **A conformance suite** (`SourceConformanceTestCase`,
   `DestinationConformanceTestCase`) for third-party plugin authors.

The platform deliberately does **not** ship source readers for specific formats.
Those land as separate packages (`waaseyaa-migrate-source-wordpress` etc.) and
depend on `waaseyaa/migration` as their substrate.

### When to use it

- An application needs to import content from a legacy CMS, a CSV export, or
  any other off-platform system into Waaseyaa entities.
- A package author wants to ship a reusable source reader that any Waaseyaa
  application can consume.
- An operator wants resumable, rollback-safe imports with deterministic
  re-runs.

### When not to use it

- Drupal-style "site rebuild" flows where the entire database is recreated on
  each deploy — those belong in seeders, not migrations.
- Real-time content syndication (use the ingestion package + North Cloud).
- One-shot data fixes that are easier expressed as a CLI script (use a custom
  `bin/waaseyaa` command).

---

## 2. Layer placement

| Layer | Name | Position |
|---|---|---|
| 3 | Services | The migration package depends on Layers 0–2 (Foundation, Core Data, Content Types) but is itself depended on only by other Layer 3 services, Layer 4 (API), and Layer 6 (Interfaces — CLI). |

Source-reader packages (e.g. `waaseyaa-migrate-source-wordpress`) sit at the
same conceptual layer but live in separate Composer packages outside the
framework monorepo.

---

## 3. Stable surface

The following symbols are stable per charter §5.8. Breaking changes follow the
charter's deprecation cycle. FQCN root: `Waaseyaa\Migration\`.

### 3.1 Plugin contracts

| FQCN | Kind | Purpose |
|---|---|---|
| `Waaseyaa\Migration\Plugin\SourcePluginInterface` | Interface | Streams `SourceRecord` instances from an external source. |
| `Waaseyaa\Migration\Plugin\ProcessPluginInterface` | Interface | Transforms a single source value into a destination value. |
| `Waaseyaa\Migration\Plugin\DestinationPluginInterface` | Interface | Writes a `DestinationRecord` to its target system and supports rollback + lookup. |

### 3.2 Provider capabilities

| FQCN | Kind | Purpose |
|---|---|---|
| `Waaseyaa\Migration\Discovery\HasMigrationsInterface` | Provider capability | Surfaces concrete `MigrationDefinition` instances. |
| `Waaseyaa\Migration\Discovery\HasMigrationPluginsInterface` | Provider capability | Surfaces source/process/destination plugin instances. |

### 3.3 Manifest + DTOs

| FQCN | Kind | Purpose |
|---|---|---|
| `Waaseyaa\Migration\MigrationDefinition` | `final readonly class` | Manifest: id, source, process map, destination, dependencies. |
| `Waaseyaa\Migration\SourceId` | `final readonly class` | Composite primary key (`sourceType` + ordered `keys`); deterministic sha256 hash. |
| `Waaseyaa\Migration\Plugin\SourceRecord` | `final readonly class` | One record as emitted by a source plugin. |
| `Waaseyaa\Migration\Plugin\DestinationRecord` | `final readonly class` | One record ready to write to a destination. |
| `Waaseyaa\Migration\Plugin\WriteResult` | `final readonly class` | Outcome of a successful destination write (uuid, runId, hash). |
| `Waaseyaa\Migration\Plugin\ProcessContext` | `final readonly class` | Context threaded into `ProcessPluginInterface::transform()`. |

### 3.4 Concrete destination

| FQCN | Kind | Purpose |
|---|---|---|
| `Waaseyaa\Migration\Plugin\Destination\EntityDestination` | Concrete (stable) | Default destination — writes entity values through the entity-storage coordinator. |
| `Waaseyaa\Migration\Plugin\Destination\EntityDestinationFactory` | Concrete (stable) | Factory binding entity type + bundle. |

### 3.5 Process plugin concretes

The framework reserves six process-plugin ids. App-defined process plugins MUST
use a non-reserved id; convention is `<vendor>_<purpose>` (e.g.
`wordpress_shortcode_strip`).

| FQCN | Reserved id |
|---|---|
| `Waaseyaa\Migration\Plugin\Process\PassThroughProcessor` | `pass_through` |
| `Waaseyaa\Migration\Plugin\Process\HtmlSanitizeProcessor` | `html_sanitize` |
| `Waaseyaa\Migration\Plugin\Process\LookupProcessor` | `lookup` |
| `Waaseyaa\Migration\Plugin\Process\ConcatProcessor` | `concat` |
| `Waaseyaa\Migration\Plugin\Process\TypeCoerceProcessor` | `type_coerce` |
| `Waaseyaa\Migration\Plugin\Process\DefaultValueProcessor` | `default_value` |

Reserved ids are owned by the framework. The complete list lives in
`Waaseyaa\Migration\Plugin\ReservedPluginIds`.

**Security note (B-4, nested-case + CDATA-content-model fixpoint — audit-remediation batch 2026-07-02, R3 WP1):**
`HtmlSanitizeProcessor`'s DOMDocument fallback path (used whenever
`ezyang/htmlpurifier` is not installed) closes two sibling stored-XSS bypasses
of the same B-4 class:

- **Nested-wrapper hoist.** The filter re-checks every element it hoists out
  of a stripped disallowed wrapper, not just top-level children. A disallowed
  tag or an unsafe-scheme URL attribute nested inside another disallowed tag
  (e.g. `<span><img onerror=…></span>`, or a `javascript:` href nested inside a
  stripped `<div>`) is re-run against the tag allowlist and
  `filterAttributes()`/`hasUnsafeUrlScheme()` at every promotion depth, to a
  fixpoint — nesting the payload below a disallowed wrapper cannot bypass the
  allowlist. See `HtmlSanitizeProcessor::applyChildPolicy()` /
  `HtmlSanitizeProcessor::replaceWithTextContent()`.
- **CDATA content model.** libxml2 parses the inner payload of the raw-text
  content-model tags `xmp` / `iframe` / `noembed` / `noframes` / `plaintext`
  (alongside `script` / `style`) as a `\DOMCdataSection`, which
  `DOMDocument::saveHTML()` serializes verbatim (no entity escaping), so a
  `<script>`/`onerror`/`javascript:` payload wrapped in one of them survives as
  live markup with no nesting. These tags are enumerated in
  `RAW_TEXT_CONTENT_MODEL_TAGS` and are **force-stripped even when a custom
  allowlist names them** (escaping is decided by a node's parent content model,
  so a payload kept inside such a wrapper cannot be escaped in place); their
  content is routed through the hoist path and neutralized by
  `convertCdataToText()` (CDATA → entity-escaped text node) — or dropped
  wholesale for `script`/`style`. RCDATA tags `title` / `textarea` are
  deliberately exempt: libxml parses them as `\DOMText`, which saveHTML escapes.

### 3.6 Schema

| Table | Source-of-truth descriptor | Purpose |
|---|---|---|
| `migration_id_map` | `Waaseyaa\Migration\Schema\MigrationIdMapSchema` | Maps `(migration_id, source_id_hash)` to `(destination_entity_type, destination_uuid, source_record_hash, last_run_id, last_imported_at)`. Enables idempotency, lookup, and rollback. |

The `migration_id_map` table layout is **frozen stable surface**. Future
column changes require a charter amendment and a data migration of every
existing row.

### 3.7 Exception types

| FQCN | Raised by |
|---|---|
| `Waaseyaa\Migration\Exception\MigrationCycleException` | Discovery — when migration dependency graph contains a cycle. |
| `Waaseyaa\Migration\Exception\MigrationPluginCollisionException` | Discovery — when two plugins claim the same id. |
| `Waaseyaa\Migration\Exception\MigrationDependencyMissingException` | Discovery — when a declared dependency is not registered. |
| `Waaseyaa\Migration\Exception\SourceReadException` | Runner — when a source plugin fails mid-stream. |
| `Waaseyaa\Migration\Exception\ProcessException` | Runner — when a process plugin throws. |
| `Waaseyaa\Migration\Exception\DestinationWriteException` | Runner — when a destination write fails (access, validation, atomicity). |
| `Waaseyaa\Migration\Exception\MigrationAbortedException` | Runner — when the error-rate halt threshold trips. |
| `Waaseyaa\Migration\Exception\MigrationConcurrencyException` | Runner — when the per-migration advisory lock is already held. |

### 3.8 Test bases (conformance)

| FQCN | Tests |
|---|---|
| `Waaseyaa\Migration\Testing\SourceConformanceTestCase` | Source plugin: id format, stability, streaming, determinism, `count()` contract, 50 MB memory budget, etc. |
| `Waaseyaa\Migration\Testing\DestinationConformanceTestCase` | Destination plugin: write/lookup/rollback round-trip, idempotency, `WriteResult` contract. |

### 3.9 CLI commands

| Command | Purpose | Exit codes |
|---|---|---|
| `bin/waaseyaa import:run <id>` | Run one migration end-to-end. | 0 success / 1 generic / 2 concurrency lock held |
| `bin/waaseyaa import:run-all` | Run every registered migration in dependency order, acquiring the lock per migration. | 0 success / 1 any failure / 2 concurrency |
| `bin/waaseyaa import:status [<id>]` | Read-only status of one or all migrations. No lock. | 0 always (status is informational) |
| `bin/waaseyaa import:resume <id>` | Resume a previously-failed run from the last committed `migration_run_state` cursor. | 0 success / 1 failure / 2 concurrency |
| `bin/waaseyaa import:rollback <id>` | Walk `migration_id_map` in reverse; call `DestinationPluginInterface::rollback()` on each row; remove the id-map rows on success. | 0 success / 1 failure / 2 concurrency |
| `bin/waaseyaa import:reset <id>` | Drop `migration_id_map` rows for the migration without calling rollback. Use only when destination state has already been wiped externally. | 0 success / 1 failure / 2 concurrency |

CLI command sources live under `packages/cli/src/Command/Import/`.

### 3.10 Log channel

| Channel | Purpose |
|---|---|
| `migration.deprecation` | "Experimental plugin used" notices. Emitted at most once per plugin id per process. Constant: `Waaseyaa\Migration\Log\Channels::MIGRATION_DEPRECATION`. |

The `entity.lifecycle` channel (owned by entity-storage) is reused for
access-denial and rollback-best-effort log lines.

### 3.11 `SaveContext::isImport()` extension

`EntityDestination::write()` constructs a `SaveContext` with `isImport: true`.
Lifecycle subscribers can branch on `$event->context->isImport()` to skip
expensive non-essential work (cache invalidation, analytics) during imports.
The new method extends charter §5.3; it is additive (default `false`).

---

## 4. Plugin contracts (full signatures)

### 4.1 `SourcePluginInterface` (@spec FR-001)

```php
namespace Waaseyaa\Migration\Plugin;

interface SourcePluginInterface
{
    public function id(): string;
    public function stability(): string;             // 'stable' | 'experimental'
    public function records(): iterable;             // yields SourceRecord
    public function sourceIdFor(SourceRecord $record): SourceId;
    public function count(): ?int;                   // null when unknown
}
```

`records()` MUST be a generator or other lazy iterable (FR-061a). The
conformance suite asserts the 50 MB memory budget is respected.

### 4.2 `ProcessPluginInterface` (@spec FR-002)

```php
namespace Waaseyaa\Migration\Plugin;

interface ProcessPluginInterface
{
    public function id(): string;
    public function stability(): string;
    public function transform(mixed $value, ProcessContext $context): mixed;
}
```

`ProcessContext` carries the full `SourceRecord`, the `MigrationDefinition`,
and a lookup callable bound to `MigrationIdMap` for cross-migration ID
resolution.

### 4.3 `DestinationPluginInterface` (@spec FR-003)

```php
namespace Waaseyaa\Migration\Plugin;

interface DestinationPluginInterface
{
    public function id(): string;
    public function stability(): string;
    public function write(DestinationRecord $record): WriteResult;
    public function rollback(WriteResult $result): void;
    public function lookup(SourceId $sourceId): ?WriteResult;
}
```

`lookup()` is the consult-id-map operation, used by process plugins
(`LookupProcessor`) to resolve cross-migration references.

---

## 5. Manifest format

`MigrationDefinition` is the single object every migration author writes.

```php
final readonly class MigrationDefinition
{
    public function __construct(
        public string $id,                                   // /^[a-z][a-z0-9_]*$/
        public SourcePluginInterface $source,
        /** @var array<string, ProcessPluginInterface|string|array<ProcessPluginInterface|string>> */
        public array $process,                               // non-empty
        public DestinationPluginInterface $destination,
        public array $dependencies = [],                     // ids of other migrations
        public ?string $description = null,
        public int $memoryBudgetBytes = 268_435_456,         // 256 MB default
        public float $errorRateWarn = 0.01,
        public float $errorRateHalt = 0.10,
        public ?string $bundle = null,                       // threaded into every DestinationRecord (§7.1)
    ) { /* validates */ }
}
```

### 5.1 Process map shapes

Each `process` value can be:

1. **A string** (`'username'`) — shorthand for `new PassThroughProcessor('username')`.
2. **A `ProcessPluginInterface` instance** (`new HtmlSanitizeProcessor('bio_html')`).
3. **An array of strings + processors** (`[new PassThroughProcessor('signup_year'), new TypeCoerceProcessor('int')]`) — a chain. The runner threads the output of each plugin into the input of the next.

### 5.2 Discovery

Migrations are surfaced through `HasMigrationsInterface` on a service provider:

```php
final class MyMigrationProvider extends ServiceProvider implements HasMigrationsInterface
{
    public function migrations(): array
    {
        return [UsersCsvToWidgetsMigration::create($this->container)];
    }
}
```

Reserved-id plugins surface through `HasMigrationPluginsInterface`. The
`FrameworkPlugin` namespace prefix is reserved — only first-party plugins may
register ids in that namespace.

---

## 6. Storage

### 6.1 `migration_id_map` (stable surface)

Tracks the source → destination mapping for every row a migration has written.
Columns (per `MigrationIdMapSchema`):

| Column | Type | Purpose |
|---|---|---|
| `migration_id` | TEXT NOT NULL | Migration id from `MigrationDefinition::$id`. |
| `source_id_hash` | TEXT NOT NULL | sha256 of `SourceId::hash()` canonical form. |
| `destination_entity_type` | TEXT NOT NULL | Target entity type id. |
| `destination_uuid` | TEXT NOT NULL | UUIDv7 of the persisted entity. |
| `source_record_hash` | TEXT NOT NULL | Canonical hash of the destination values; used for change detection. |
| `last_run_id` | TEXT NOT NULL | UUIDv7 of the run that most recently wrote this row. Refreshed on every re-import (`upsert()` UPDATE). |
| `last_imported_at` | TEXT NOT NULL | ISO 8601 UTC timestamp of the most recent write. Refreshed on every re-import; NOT a creation timestamp. Sole ordering signal for the reverse rollback walk (FR-043). |

Primary key: `(migration_id, source_id_hash)`. Unique index:
`(destination_entity_type, destination_uuid)`.

Migration file:
`packages/migration/migrations/2026_05_13_000001_create_migration_id_map.php`.

### 6.2 `migration_run_state` (mission-internal)

Captures resume cursors and per-run progress. **Not** stable surface — it is
mission-internal infrastructure. Apps and extensions MUST NOT depend on its
shape; future re-implementations of the runner may replace it.

Source-of-truth: `Waaseyaa\Migration\Schema\MigrationRunStateSchema`.

### 6.3 `storage/migration-locks/<migration-id>.lock` (mission-internal)

Per-migration advisory lock (flock-based, FR-061). One file per migration_id.
Held for the duration of an `import:run`, `import:resume`, `import:rollback`,
or `import:reset`. Read-only `import:status` does not acquire the lock.

**Operator recovery** (per WP09 D11): if a process is force-killed leaving a
stale lock, the operator removes the lock file manually:

```
rm storage/migration-locks/<migration-id>.lock
```

`flock()` releases automatically on process exit, including most crash paths,
so this is rare in practice. There is no automated stale-lock detector by
design — manual recovery preserves operator control.

---

## 7. EntityDestination

The default destination — writes through the entity-storage coordinator
(ADR 010), threads `SaveContext::$isImport = true`, respects lifecycle events
(`BeforeSaveEvent`, `AfterSaveEvent`), and integrates with revisions
(ADR 016).

### 7.1 Construction

`EntityDestinationFactory::forEntityType('migration_test_widget')` returns an
`EntityDestination` bound to a specific destination entity type id. Bundle
resolution is deferred to write time (D8): a migration author declares the
bundle once on `MigrationDefinition::$bundle`; `MigrationRunner::processOne()`
threads that value verbatim into `DestinationRecord::$bundle` for every
record it builds; `EntityDestination` reads the typed `$bundle` property
(not a `$fields` array — there is no such slot) at write time and, when
non-null, resolves the destination entity type's bundle key
(`EntityType::getKeys()['bundle']`) and sets it on the entity before save.

### 7.2 Write path (FR-018..FR-022)

1. Load existing entity by id-map lookup. If present and `source_record_hash`
   matches, no-op (idempotent re-run).
2. If absent, instantiate the entity with a freshly-generated UUIDv7.
3. Set field values from `DestinationRecord::$fields`.
4. Construct `SaveContext(isImport: true)`; dispatch `BeforeSaveEvent`; call
   `EntityStorageCoordinator::save()`; dispatch `AfterSaveEvent`.
5. `upsert` the id-map row with the new `source_record_hash`.
6. Return a `WriteResult`.

### 7.3 Rollback path (FR-035, FR-041–FR-044)

`EntityDestination::rollback(WriteResult $result)` deletes the destination
entity via the storage coordinator. The id-map row is removed by the runner
**after** the destination rollback succeeds (FR-042 — id-map retention until
destination is empty). If rollback fails, the id-map row is preserved so the
operator can retry.

**Rollback vs FR-042 idempotent re-run (issue #1452).** FR-042 governs
re-running an unchanged record — a separate code path from `rollback()`.
Whether `rollback()` clears the id-map row alongside the entity is an
implementation-defined retention policy: the framework default clears it,
audit/replay-oriented destinations may retain it. Both modes are
conformant; the conformance harness gates on
`DestinationConformanceTestCase::rollbackClearsLookup()`. See
`kitty-specs/migration-platform-v1-01KRCDE9/contracts/destination-plugin.md`
§"Conformance requirements (WP10)" for the normative statement.

### 7.4 Lookup path

`EntityDestination::lookup(SourceId $sourceId)` returns the `WriteResult`
captured at last write, or `null` if no id-map row exists. Used by
`LookupProcessor` to resolve cross-migration references.

---

## 8. CLI runner

See §3.9 for the command + exit-code table. Detailed flag semantics:

### 8.1 `import:run <id>`

- Acquires the per-migration lock.
- Streams `SourceRecord`s from the source plugin.
- For each record: computes `SourceId`, looks up the id-map, runs the process
  map, calls `DestinationPluginInterface::write()`.
- Commits `migration_run_state` cursor after each successful write.
- Aborts when the error-rate halt threshold (`MigrationDefinition::$errorRateHalt`,
  default 10 %) trips → raises `MigrationAbortedException`, exits with non-zero
  status.
- Releases the lock on exit (success, failure, or signal).

### 8.2 `import:run-all`

Walks the dependency graph in topological order and runs each migration. The
lock is acquired and released **per migration**, not once for the whole walk —
a long-running multi-migration import does not block parallel operator work
on unrelated migrations.

### 8.3 `import:resume <id>`

Reads the last committed cursor from `migration_run_state` and re-streams the
source plugin, skipping records up to that cursor before resuming writes.

### 8.4 `import:rollback <id>`

Walks `migration_id_map` in reverse last-imported order (`last_imported_at
DESC`, tie-broken by `last_run_id DESC`). For each row, calls
`DestinationPluginInterface::rollback()`, then removes the id-map row on
success. A failed rollback halts the walk with the id-map intact.

Note: this is *last-imported* order, not creation order — the stable-surface
table (FR-025) has no immutable creation column, and `last_imported_at` is
refreshed on every re-import. See FR-043 for why this is the correct
contract for best-effort rollback.

### 8.5 `import:reset <id>`

Drops id-map rows for the migration without calling rollback. Use only when
destination state has already been wiped externally (e.g. `DROP TABLE node`).

---

## 9. Discovery + boot sequence

1. **`PackageManifestCompiler`** scans `extra.waaseyaa.providers` in every
   installed package's `composer.json`.
2. For each provider class:
   - If it implements `HasMigrationsInterface`, its `migrations()` array is
     fed into `MigrationRegistry`.
   - If it implements `HasMigrationPluginsInterface`, its `migrationPlugins()`
     array is dispatched by `instanceof` into the source / process /
     destination sub-registries (`PluginRegistry`).
3. `DependencyGraph` is built from `MigrationDefinition::$dependencies` and
   validated via `CycleDetector`.
4. `bin/waaseyaa optimize:manifest` rebuilds this index on demand; otherwise
   it lives in the boot-time manifest cache.

---

## 10. Conformance suite

Third-party plugin authors verify their implementations by subclassing the
appropriate base case and implementing two or three factory methods.

### 10.1 `SourceConformanceTestCase`

Asserts:
- `id()` matches `/^[a-z][a-z0-9_]*$/` and is stable across calls.
- `stability()` returns `'stable'` or `'experimental'`.
- `records()` is a generator (lazy).
- Two calls to `records()` yield the same `SourceId`s in the same order
  (determinism — FR-027).
- `count()` either returns a non-negative int or `null`.
- Importing a 50 MB fixture stays within the configured memory budget.

### 10.2 `DestinationConformanceTestCase`

Asserts:
- Round-trip: `write()` produces a `WriteResult`; `lookup(sourceId)` returns
  it.
- Idempotency: writing the same `DestinationRecord` twice produces the same
  `WriteResult` and does not duplicate destination state.
- Rollback: `rollback(writeResult)` removes the destination row; a subsequent
  `lookup()` returns `null`.

Reference implementation:
`packages/migration/tests/Integration/EntityDestinationTest.php` shadows the
conformance asserts on `EntityDestination` itself.

---

## 11. Concurrency and recovery

- **Lock semantics:** flock-based, one file per migration_id under
  `storage/migration-locks/`. `import:status` is read-only and skips the lock.
- **Stale-lock recovery:** `rm storage/migration-locks/<id>.lock`. No
  automated tool by design (per WP09 D11).
- **Process death:** `flock()` releases the lock when the holding process
  exits, including most crash paths. Manual `rm` is only needed when the lock
  file persists after the process is gone (rare; usually a filesystem quirk).
- **`pcntl` signal handlers:** if installed, the runner catches `SIGINT` /
  `SIGTERM`, releases the lock, commits the run-state cursor, and exits
  non-zero so `import:resume` can pick up where it stopped.
- **Windows:** flock degrades to advisory-only on some filesystems; the
  platform still works but the lock is best-effort.
- **Monotonic clock clamp:** `RunReport`/`RollbackReport` both assert
  `finishedAt >= startedAt`. Since the runner's/walker's injected clock
  defaults to non-monotonic wall-clock time (`new \DateTimeImmutable('now',
  UTC)`), a backward step mid-run (NTP step, VM/WSL suspend-resume,
  leap-second smear) could otherwise trip that invariant on an already
  fully-committed run and crash the CLI with an uncaught
  `\InvalidArgumentException`. `MigrationRunner::nowClampedTo()` and
  `RollbackWalker::rollback()`'s inline clamp both capture `($this->clock)()`
  and advance a regressed finish stamp forward by 1 microsecond over
  `$startedAt` before constructing the report, so a clock regression is
  absorbed rather than surfaced as a crash (audit-remediation batch
  2026-07-02 R3 WP2, migration M2).

---

## 12. Error model

| Exception | Scenario | Suggested operator response |
|---|---|---|
| `SourceReadException` | Source plugin failed mid-stream (network, parse error). | Inspect logs, fix the source, `import:resume`. |
| `ProcessException` | A process plugin threw during `transform()`. | Inspect logs, fix the plugin, `import:resume`. |
| `DestinationWriteException` | Destination write failed (access, validation, schema). | Inspect logs, fix the destination, `import:resume`. |
| `MigrationAbortedException` | Error-rate halt threshold tripped. | Inspect logs, decide whether to raise the threshold or fix root cause, `import:resume`. |
| `MigrationConcurrencyException` | Lock held by another process. | Wait, then retry. If stale, `rm` the lock file. |
| `MigrationCycleException` | Dependency cycle detected at boot. | Fix the `MigrationDefinition::$dependencies` declarations. |
| `MigrationPluginCollisionException` | Two plugins claim the same id. | Rename one. |
| `MigrationDependencyMissingException` | A dependency id is not registered. | Register the missing migration, or remove the dependency. |

---

## 13. Charter mapping

Charter §5.8 (`docs/specs/stability-charter.md`) lists every stable-surface
symbol in this spec. Charter §5.3 (Entity / storage) is extended by
`SaveContext::isImport()`.

---

## 14. Operations playbook

A canonical operator-facing walk-through lives at
`docs/guides/migration-operator.md`. Plugin authors should read
`docs/extension-authoring/migration-source-readers.md` and
`docs/extension-authoring/migration-process-plugins.md`. A first-time tutorial
lives at `docs/cookbook/migration-first-cut.md`.

---

## 15. Related ADRs

- [ADR 010 — multi-backend field storage](../adr/010-multi-backend-field-storage.md) — governs the entity-storage coordinator that `EntityDestination` writes through.
- [ADR 011 — entity lifecycle events](../adr/011-entity-lifecycle-events.md) — `SaveContext::isImport()` is the migration platform's extension to this contract.
- [ADR 012a — migration substrate in core](../adr/012a-migration-substrate-in-core.md) — origin ADR for this mission.
- [ADR 016 — revisions first-class](../adr/016-revisions-first-class.md) — `EntityDestination` writes through the revisionable coordinator path when the entity type opts in.

---

## 16. History

- 2026-07-02 — Audit-remediation batch, R3 WP1 (M1). Closed two sibling
  stored-XSS bypasses in `HtmlSanitizeProcessor`'s DOMDocument fallback path,
  both reopening B-4: (1) a **nested-wrapper** bypass —
  `replaceWithTextContent()` hoisted a disallowed wrapper's descendants
  verbatim without re-running the tag allowlist or `filterAttributes()`/
  URL-scheme check on the promoted nodes, so a dangerous element or attribute
  nested one or more levels below a disallowed tag survived untouched; fixed
  by re-applying the per-child policy (`applyChildPolicy()`) to every promoted
  node at every hoist depth, to a fixpoint. (2) a **CDATA-content-model**
  bypass — libxml parses the payload of `xmp`/`iframe`/`noembed`/`noframes`/
  `plaintext` as a `\DOMCdataSection` that saveHTML emits verbatim, so a live
  `<script>`/`onerror`/`javascript:` survived with no nesting; fixed by
  force-stripping those tags (`RAW_TEXT_CONTENT_MODEL_TAGS`) even when
  custom-allowlisted and converting CDATA to entity-escaped text
  (`convertCdataToText()`). See §3.5 for the security note.
- 2026-05-13 — Mission `migration-platform-v1-01KRCDE9` (M-002) lands on
  `main`. WP01 through WP11 ship code; WP12 ships this document plus the
  charter §5.8 amendment and CHANGELOG entry.

Planning archive (specify / plan / tasks / reviews / contracts):
`kitty-specs/migration-platform-v1-01KRCDE9/`.
