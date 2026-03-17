# Migration Protocol: App-Level Discovery & CLI Wiring

**Date:** 2026-03-17
**Status:** Draft
**Scope:** Complete the existing migration engine with app-level discovery and CLI commands

## Context

Waaseyaa already has a fully functional migration engine in `packages/foundation/src/Migration/`:

- **`Migration`** — abstract base class with `up(SchemaBuilder)` / `down(SchemaBuilder)` and `$after` dependency ordering
- **`Migrator`** — topological sort by package dependencies, batch tracking, skip-already-run, rollback-last-batch
- **`MigrationRepository`** — `waaseyaa_migrations` tracking table with migration name, package, batch number, timestamp
- **`SchemaBuilder`** — Doctrine DBAL wrapper with `create()`, `drop()`, `dropIfExists()`, `hasTable()`, `hasColumn()`
- **`TableBuilder`** — fluent column DSL (`id()`, `string()`, `text()`, `json()`, `timestamps()`, `entityBase()`, etc.)
- **`MigrationResult`** — result DTO
- **`make:migration`** — CLI stub generator
- **Package discovery** — `composer.json` `extra.waaseyaa.migrations` declares migration directories per package

The engine, registry, tracking table, and package-level discovery are all built. What's missing is the last 10% that makes it usable by real apps:

1. **App-level migration discovery** — apps (Claudriel, Minoo) are not packages and have nowhere to put migrations that the framework discovers
2. **CLI commands wired to the Migrator** — no `migrate`, `migrate:rollback`, or `migrate:status` commands exist

## Design Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Migration contract | Single `Migration` base class | One contract, one abstraction, one mental model. Raw SQL available via `SchemaBuilder::getConnection()` escape hatch. |
| App migration path | `migrations/` at project root (hardcoded convention) | Convention over configuration. Matches how `config/` and `defaults/` work. Zero config, zero misconfiguration. |
| Transaction wrapping | Wrap each `up()`/`down()` in `$connection->transactional()` | Atomicity where supported (SQLite: full DDL transactionality). Best-effort on other engines. |
| Rollback scope | Last batch only | Matches deploy model. Avoids dangerous multi-batch rollback footguns. |
| Auto-run on boot | No | Migrations are intentional state transitions, not idempotent checks. Explicit `bin/waaseyaa migrate` required. |

## What We're Adding

### 1. MigrationLoader

New class in `packages/foundation/src/Migration/MigrationLoader.php`.

```php
final class MigrationLoader
{
    public function __construct(
        private readonly string $basePath,
        private readonly PackageManifest $manifest,
    ) {}

    /**
     * @return array<string, array<string, Migration>>  package => [name => Migration]
     */
    public function loadAll(): array;
}
```

**Discovery rules:**

1. Iterates `$manifest->migrations` (the `[packageName => path]` map) — loads each package's migration files
2. Checks `$basePath . '/migrations'` — if the directory exists, loads those files under the synthetic package key `'app'`
3. Each `.php` file must `return new class extends Migration { ... }`. The loader validates the return value — if a file returns something other than a `Migration` instance, it throws a `\RuntimeException` with the filename.
4. Files are sorted alphabetically within each package (timestamp prefix gives natural ordering)
5. The migration "name" is `{package}:{filename}` (e.g., `waaseyaa/node:20260317_143000_create_node_table`, `app:20260317_143000_add_social_posts`). This namespacing prevents collisions when different packages have migration files with the same filename.
6. App migrations implicitly run after all package migrations — the loader places `'app'` last in the dependency graph

No caching — scans on every call. Migrations run infrequently and directories are small.

### 2. SchemaBuilder::getConnection()

One-line addition to `packages/foundation/src/Migration/SchemaBuilder.php`:

```php
public function getConnection(): Connection
{
    return $this->connection;
}
```

Exposes the Doctrine DBAL `Connection` for migrations that need raw SQL. The `SchemaBuilder` DSL remains the primary interface.

### 3. Transaction wrapping in Migrator

Modify `Migrator::run()` and `Migrator::rollback()` to wrap each migration execution:

```php
// In run():
$this->connection->transactional(function () use ($migration, $schema, $name, $package, $batch) {
    $migration->up($schema);
    $this->repository->record($name, $package, $batch);
});

// In rollback():
$this->connection->transactional(function () use ($migration, $schema, $name) {
    $migration->down($schema);
    $this->repository->remove($name);
});
```

The `record()`/`remove()` calls are **inside** the transaction. If `up()` fails, neither the schema change nor the tracking record are committed. If `record()` fails after a successful `up()`, the entire transaction rolls back — preventing the state where a schema change is applied but not tracked.

**Engine caveat:** SQLite and Postgres fully support DDL transactions. MySQL implicitly commits on all DDL statements — wrapping is still beneficial for non-DDL parts and consistent error handling, but DDL cannot be rolled back on MySQL.

### 4. Kernel integration

New `bootMigrations()` method in `AbstractKernel::boot()`, called after `compileManifest()` (which produces the `PackageManifest` that `MigrationLoader` requires):

```
bootDatabase()              → creates PdoDatabase (existing)
bootEntityTypeManager()     → creates entity tables (existing)
compileManifest()           → produces PackageManifest (existing)
bootMigrations()            → wires migration components (new)
discoverAndRegisterProviders() → ... (existing)
```

`bootMigrations()` does:

1. Creates a Doctrine DBAL `Connection` wrapping the same SQLite file
2. Creates `MigrationRepository`, calls `createTable()` (idempotent — `CREATE TABLE IF NOT EXISTS`)
3. Creates `MigrationLoader` with `$basePath` and `$this->manifest`
4. Creates `Migrator` with the `Connection` and `MigrationRepository`
5. Stores `Migrator` and `MigrationLoader` as accessible properties for CLI commands
6. Does **not** auto-run migrations

**Ordering note:** `bootMigrations()` runs after `bootEntityTypeManager()`, which means entity tables are created via `ensureTable()` before migrations run. This is correct — `ensureTable()` is idempotent and handles initial table creation, while migrations handle subsequent schema evolution (adding columns, modifying tables, creating non-entity tables). A migration that adds a column to an entity table will find the table already exists.

### 5. CLI commands

Three new commands in `packages/cli/src/Command/`:

**`MigrateCommand`** (`migrate`)
- Loads migrations via `MigrationLoader::loadAll()`
- Runs `Migrator::run()`
- Outputs each migration name as it runs
- Reports summary: "Ran 3 migrations." or "Nothing to migrate."

**`MigrateRollbackCommand`** (`migrate:rollback`)
- Loads migrations via `MigrationLoader::loadAll()`
- Runs `Migrator::rollback()` (last batch, reverse order)
- Outputs each rolled-back migration name
- Reports summary

**`MigrateStatusCommand`** (`migrate:status`)
- Loads migrations via `MigrationLoader::loadAll()`
- Runs `Migrator::status()` — currently returns `{pending: list<string>, completed: list<string>}`
- Extend `Migrator::status()` to return richer data: query the repository for batch number and package per completed migration
- Outputs a table: migration name, package, status (Pending/Ran), batch number

All three receive `Migrator` and `MigrationLoader` from the kernel.

### 6. make:migration update

Update `MakeMigrationCommand` to write the generated stub file directly to `migrations/` (creating the directory if needed) instead of printing to stdout. File is named with current timestamp: `YYYYMMDD_HHMMSS_{name}.php`.

## Migration file conventions

**Location:** `migrations/` at project root for apps; package-declared paths for packages.

**Naming:** `YYYYMMDD_HHMMSS_description.php` — timestamp prefix for ordering, snake_case description.

**Format:**

```php
<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        $schema->create('social_posts', function ($table) {
            $table->id();
            $table->string('title', 255);
            $table->text('body')->nullable();
            $table->timestamps();
        });
    }

    public function down(SchemaBuilder $schema): void
    {
        $schema->dropIfExists('social_posts');
    }
};
```

**Raw SQL escape hatch:**

```php
public function up(SchemaBuilder $schema): void
{
    $schema->getConnection()->executeStatement(
        'ALTER TABLE "group" ADD COLUMN social_posts TEXT'
    );
}
```

**Package dependencies:**

```php
return new class extends Migration {
    public array $after = ['waaseyaa/entity-storage'];
    // ...
};
```

App migrations (package key `'app'`) implicitly run after all package migrations.

## What We're NOT Adding

- **No second migration contract** — one `Migration` base class for everything
- **No configurable migration paths** — convention over configuration
- **No `--step=N` rollback** — last batch only, matching the deploy model
- **No auto-run on boot** — explicit CLI command required
- **No schema DSL changes** — existing `SchemaBuilder`/`TableBuilder` are sufficient

## Files to create

| File | Description |
|------|-------------|
| `packages/foundation/src/Migration/MigrationLoader.php` | Discovers and loads migrations from packages and app directory |
| `packages/cli/src/Command/MigrateCommand.php` | `migrate` CLI command |
| `packages/cli/src/Command/MigrateRollbackCommand.php` | `migrate:rollback` CLI command |
| `packages/cli/src/Command/MigrateStatusCommand.php` | `migrate:status` CLI command |

## Files to modify

| File | Change |
|------|--------|
| `packages/foundation/src/Migration/SchemaBuilder.php` | Add `getConnection(): Connection` |
| `packages/foundation/src/Migration/Migrator.php` | Wrap `up()`/`down()` in `transactional()` |
| `packages/foundation/src/Kernel/AbstractKernel.php` | Add `bootMigrations()` step |
| `packages/cli/src/Command/Make/MakeMigrationCommand.php` | Write file to `migrations/` instead of stdout |

## Test plan

- `MigrationLoader`: discovers package migrations, discovers app migrations, merges correctly, handles missing directories, sorts alphabetically
- `Migrator` transaction wrapping: successful migration commits, failed migration rolls back and is not recorded
- `MigrateCommand`: runs pending, skips already-run, outputs correctly, handles nothing-to-migrate
- `MigrateRollbackCommand`: rolls back last batch in reverse, handles nothing-to-rollback
- `MigrateStatusCommand`: shows pending and completed with correct metadata
- `MakeMigrationCommand`: writes file with correct name and content to `migrations/`
- Integration: full round-trip — create migration file, run migrate, verify schema, run rollback, verify reverted
