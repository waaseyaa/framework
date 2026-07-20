# waaseyaa/database-legacy

**Layer 0 — Foundation**

Database abstraction for Waaseyaa applications, wrapping [Doctrine DBAL](https://www.doctrine-project.org/projects/dbal.html). Interim layer until a full Doctrine migration.

## Namespace

Despite the package directory being `database-legacy`, the PHP namespace is **`Waaseyaa\Database`** (not `Waaseyaa\DatabaseLegacy`). The directory name is deliberate — see [docs/adr/007-database-legacy-package-naming.md](../../docs/adr/007-database-legacy-package-naming.md) for the rationale.

## API

`DatabaseInterface` exposes a fluent query builder and supporting services:

- `select() / insert() / update() / delete()` — query builders (`DBALSelect`, `DBALInsert`, `DBALUpdate`, `DBALDelete`)
- `schema()` — DDL via `DBALSchema`
- `transaction()` — `DBALTransaction`
- `ConsistentReadDatabaseInterface::consistentReadTransaction()` — a
  repeatable-read transaction for authorization decisions that span multiple
  storage reads; `DBALDatabase` restores the prior connection isolation when
  the transaction finishes
- `query()`, `quoteIdentifier()` — raw escape hatch + identifier quoting

`DBALDatabase` is the concrete implementation. `DBALDatabase::createSqlite()` builds an in-memory SQLite instance for tests.

```php
$db = DBALDatabase::createSqlite();           // in-memory, for tests
$rows = $db->select('node', 'n')
    ->fields('n', ['id', 'title'])
    ->condition('n.status', 1)
    ->execute();
```

Query-builder notes (empty `IN`, LIKE escaping, `fetchAssociative()`) live in the root `CLAUDE.md` under "Database / DBAL".

## Status

This is the framework's sole live database layer (depended on by `core`, `api`, `entity-storage`, `audit`, and ~12 other packages). A full retirement was implemented and merged, then reverted (2026-05-26); the package remains in active use pending the Doctrine migration. Do not treat "legacy" as "deprecated/removable".
