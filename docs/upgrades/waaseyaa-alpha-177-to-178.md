# Upgrade Guide: waaseyaa alpha.177 → alpha.178

**Released:** 2026-05-13
**Migration effort:** small (additive only; no existing call sites change)
**Required for:** apps that will use the migration platform; apps that subscribe to entity-lifecycle events should be aware of the new `SaveContext::isImport()` flag.

---

## Summary

Mission `migration-platform-v1-01KRCDE9` (M-002) lands the **migration
platform** — a stable framework substrate for importing content from external
systems (WordPress, Drupal, CSV, JSON, custom APIs) into Waaseyaa entities.
It ships:

- Plugin contracts (`SourcePluginInterface`, `ProcessPluginInterface`,
  `DestinationPluginInterface`)
- A manifest format (`MigrationDefinition`)
- The default `EntityDestination` (writes through the entity-storage
  coordinator)
- Six built-in process plugins (`PassThroughProcessor`,
  `HtmlSanitizeProcessor`, `LookupProcessor`, `ConcatProcessor`,
  `TypeCoerceProcessor`, `DefaultValueProcessor`)
- Six CLI commands (`import:run`, `import:run-all`, `import:status`,
  `import:resume`, `import:rollback`, `import:reset`)
- A `migration_id_map` schema for idempotency, change detection, and
  rollback
- A conformance suite for third-party source / destination plugins
- An additive `SaveContext::isImport()` flag (extends charter §5.3)

There are **no breaking changes**. The mission is purely additive — apps and
extensions that don't use the migration platform see no behavioural change.

The new stable surface is canonicalised in
[`docs/specs/stability-charter.md`](../specs/stability-charter.md) §5.8, and
the subsystem spec lives at
[`docs/specs/migration-platform.md`](../specs/migration-platform.md).

---

## Breaking changes

None.

---

## Deprecations

None.

---

## New stable surface

Full list in charter §5.8. Highlights:

### Interfaces

| FQCN | Purpose |
|---|---|
| `Waaseyaa\Migration\Plugin\SourcePluginInterface` | Streams `SourceRecord` from an external system. |
| `Waaseyaa\Migration\Plugin\ProcessPluginInterface` | Transforms a single source value into a destination value. |
| `Waaseyaa\Migration\Plugin\DestinationPluginInterface` | Writes a `DestinationRecord` and supports rollback + lookup. |
| `Waaseyaa\Migration\Discovery\HasMigrationsInterface` | Provider capability surfacing `MigrationDefinition`s. |
| `Waaseyaa\Migration\Discovery\HasMigrationPluginsInterface` | Provider capability surfacing source/process/destination plugins. |

### Value objects + DTOs

| FQCN | Purpose |
|---|---|
| `Waaseyaa\Migration\MigrationDefinition` | Manifest object — id, source, process map, destination, dependencies. |
| `Waaseyaa\Migration\SourceId` | Composite primary key with deterministic sha256 hash. |
| `Waaseyaa\Migration\Plugin\SourceRecord` | One record emitted by a source plugin. |
| `Waaseyaa\Migration\Plugin\DestinationRecord` | One record ready to write. |
| `Waaseyaa\Migration\Plugin\WriteResult` | Outcome of a successful write. |
| `Waaseyaa\Migration\Plugin\ProcessContext` | Context threaded into `transform()`. |

### Concrete destination

| FQCN | Purpose |
|---|---|
| `Waaseyaa\Migration\Plugin\Destination\EntityDestination` | Default destination — writes via the entity-storage coordinator. |
| `Waaseyaa\Migration\Plugin\Destination\EntityDestinationFactory` | Factory binding entity type + bundle. |

### Process plugin concretes

Reserved ids — owned by the framework:

| FQCN | Reserved id |
|---|---|
| `Waaseyaa\Migration\Plugin\Process\PassThroughProcessor` | `pass_through` |
| `Waaseyaa\Migration\Plugin\Process\HtmlSanitizeProcessor` | `html_sanitize` |
| `Waaseyaa\Migration\Plugin\Process\LookupProcessor` | `lookup` |
| `Waaseyaa\Migration\Plugin\Process\ConcatProcessor` | `concat` |
| `Waaseyaa\Migration\Plugin\Process\TypeCoerceProcessor` | `type_coerce` |
| `Waaseyaa\Migration\Plugin\Process\DefaultValueProcessor` | `default_value` |

### Schema

| Symbol | Purpose |
|---|---|
| `Waaseyaa\Migration\Schema\MigrationIdMapSchema` | Source of truth for the `migration_id_map` table. |

The `migration_id_map` table layout is **frozen stable surface**.

### Exceptions

| FQCN | Raised when |
|---|---|
| `Waaseyaa\Migration\Exception\MigrationCycleException` | Dependency graph has a cycle. |
| `Waaseyaa\Migration\Exception\MigrationPluginCollisionException` | Two plugins claim the same id. |
| `Waaseyaa\Migration\Exception\MigrationDependencyMissingException` | Declared dependency not registered. |
| `Waaseyaa\Migration\Exception\SourceReadException` | Source plugin fails mid-stream. |
| `Waaseyaa\Migration\Exception\ProcessException` | Process plugin throws during `transform()`. |
| `Waaseyaa\Migration\Exception\DestinationWriteException` | Destination write fails. |
| `Waaseyaa\Migration\Exception\MigrationAbortedException` | Error-rate halt threshold tripped. |
| `Waaseyaa\Migration\Exception\MigrationConcurrencyException` | Per-migration advisory lock already held. |

### Test bases

| FQCN | Purpose |
|---|---|
| `Waaseyaa\Migration\Testing\SourceConformanceTestCase` | Conformance gates for third-party source plugins. |
| `Waaseyaa\Migration\Testing\DestinationConformanceTestCase` | Conformance gates for third-party destination plugins. |

### Log channel

| Channel | Purpose |
|---|---|
| `migration.deprecation` | Emitted once per process when an `'experimental'` plugin is first used. Constant: `Waaseyaa\Migration\Log\Channels::MIGRATION_DEPRECATION`. |

### `SaveContext::isImport()` (charter §5.3 extension)

`Waaseyaa\EntityStorage\SaveContext` gains:

```php
public function isImport(): bool
```

`EntityDestination::write()` constructs the context with `isImport: true`.
Subscribers receive the existing `BeforeSaveEvent` / `AfterSaveEvent` and
can branch on `$event->context->isImport()` to skip non-critical work during
imports. **Default is `false`** — no existing subscriber needs to change.

### CLI commands

| Command | Purpose |
|---|---|
| `bin/waaseyaa import:run <id>` | Run one migration end-to-end. |
| `bin/waaseyaa import:run-all` | Run every registered migration in dependency order. |
| `bin/waaseyaa import:status [<id>]` | Read-only status. |
| `bin/waaseyaa import:resume <id>` | Resume from last committed cursor. |
| `bin/waaseyaa import:rollback <id>` | Walk id-map in reverse, unwind destination. |
| `bin/waaseyaa import:reset <id>` | Drop id-map rows without calling rollback (recovery). |

---

## Migration steps for consumer apps

If your app intends to **use** the migration platform:

1. `composer require waaseyaa/migration:^0.2`
2. `composer dump-autoload --optimize`
3. Apply the new schema migrations:
   ```
   bin/waaseyaa migrate
   ```
   This creates `migration_id_map` and `migration_run_state`.
4. Optional: `bin/waaseyaa optimize:manifest` to surface any
   `HasMigrationsInterface` / `HasMigrationPluginsInterface` providers shipped
   by you or by source-reader packages you've installed.

If your app does **not** intend to use the migration platform: no action
required. The package is opt-in; nothing changes unless you depend on
`waaseyaa/migration` and register a provider.

---

## Backward compatibility

Fully additive:

- `SaveContext::isImport()` defaults to `false`; non-aware subscribers
  behave unchanged.
- No existing class signatures change.
- No existing entity-storage behaviour changes for non-migration writes.

---

## Smoke test

```bash
# Verify the package is installed
composer show waaseyaa/migration

# Verify CLI commands are discovered
bin/waaseyaa list | grep import:

# Verify schema is applied
bin/waaseyaa schema:check
```

To verify your own destination implementation, subclass
`DestinationConformanceTestCase` against a test entity type and run:

```bash
./vendor/bin/phpunit packages/migration/tests/Integration/EntityDestinationTest.php
```

---

## Common questions

### Do I need to write migrations now?

No. The migration platform ships ready for **source-reader packages** (which
will ship separately — `waaseyaa-migrate-source-wordpress` is the first). If
your app doesn't import from external systems, you can ignore the package
entirely.

### What about source readers — when will WordPress / Drupal land?

Each source reader is a separate mission and ships as its own composer
package. The `waaseyaa/migration` substrate landing in alpha.178 is the
prerequisite — readers will follow in subsequent alphas.

### Can I write a custom destination instead of using `EntityDestination`?

Yes. Implement `DestinationPluginInterface` and subclass
`DestinationConformanceTestCase` to verify your contract. The framework's
`EntityDestination` is the **default**, not the only allowed destination.

### What happens if a migration crashes mid-stream?

`flock()` releases the lock automatically when the process exits, so the
next `import:run` or `import:resume` will succeed. If the lock file survives
the crash (rare, filesystem-quirk), remove it manually:

```bash
rm storage/migration-locks/<migration-id>.lock
```

There is no automated stale-lock detector by design — operator control over
recovery is intentional (WP09 D11).

---

## Release notes pointer

- CHANGELOG: see `[Unreleased]` (`waaseyaa/migration` package).
- Charter: `docs/specs/stability-charter.md` §5.8.
- Spec: `docs/specs/migration-platform.md`.
- Mission planning archive: `kitty-specs/migration-platform-v1-01KRCDE9/`.
