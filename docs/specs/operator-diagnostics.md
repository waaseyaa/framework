# Operator Diagnostics

## Purpose

Provides operator-facing health checks, structured diagnostic codes, and CLI reporting for the Waaseyaa framework. Operators can detect configuration issues, schema drift, database problems, and ingestion pipeline health without reading application logs.

## Architecture Overview

```
DiagnosticCode (enum) → DiagnosticEntry (value object) → DiagnosticEmitter (error_log)
                                                        ↘
BootDiagnosticReport ─→ HealthChecker ─→ HealthCheckResult[] ─→ CLI Commands
                         (service)        (value objects)        (health:check, health:report, schema:check)
```

### Key Classes

| Class | Package | Purpose |
|-------|---------|---------|
| `DiagnosticCode` | foundation | Enum of all operator-facing diagnostic codes |
| `DiagnosticEntry` | foundation | Structured log entry with code, message, context, remediation |
| `DiagnosticEmitter` | foundation | Writes `DiagnosticEntry` to `LoggerInterface::warning()` as a single-line JSON object. Encodes with `JSON_UNESCAPED_SLASHES \| JSON_UNESCAPED_UNICODE`; if `json_encode()` itself throws `JsonException` (e.g. a `NAN`/`INF` context value), catches it and falls back to a minimal hardcoded-safe line built only from the code's own ASCII backing value — the diagnostic path is best-effort observability and must never throw into its caller. |
| `BootDiagnosticReport` | foundation | Snapshot of entity type registry status at boot. Constructed only by `AbstractKernel::getBootReport()` (Kernel/-exempt); imports `Waaseyaa\Entity\EntityTypeInterface` — see "Kernel-adjacent codification" below. |
| `HealthChecker` | foundation | Runs all health checks, returns structured results. Constructed only by `AbstractKernel::buildHandlerContainer()` (Kernel/-exempt); imports `Waaseyaa\Entity\*` and `Waaseyaa\Field\FieldStorage` — see "Kernel-adjacent codification" below. `checkIngestion()` reads from an injected `Waaseyaa\Foundation\Ingestion\IngestionLogger` (optional constructor param, defaulting to `new IngestionLogger($projectRoot)`) rather than constructing one inline, so tests can substitute a logger pointed at a different root. |
| `HealthCheckerInterface` | foundation | Interface for testability (HealthChecker is final) |
| `HealthCheckResult` | foundation | Value object with pass/warn/fail factories |
| `HealthCheckCommand` | cli | `health:check` — fast health probe with table/JSON output; registered via `HasNativeCommandsInterface` |
| `HealthReportCommand` | cli | `health:report` — full diagnostic report with system info; registered via `HasNativeCommandsInterface` |
| `SchemaCheckCommand` | cli | `schema:check` — standalone schema drift detection; registered via `HasNativeCommandsInterface` |

### Kernel-adjacent codification (`HealthChecker`, `BootDiagnosticReport`)

`HealthChecker` and `BootDiagnosticReport` live in `waaseyaa/foundation` (Layer 0) but import `Waaseyaa\Entity\*` / `Waaseyaa\Field\FieldStorage` (Layer 1) — an architecturally upward reference. This was deliberately **codified as kernel-adjacent rather than relocated** in mission #1257 K6(c) (see `docs/specs/infrastructure.md` "Kernel exemption surface"): both classes are constructed exclusively by `Waaseyaa\Foundation\Kernel\AbstractKernel` (itself already exempt from the layer-import rule as an entry-point orchestrator per CLAUDE.md "Exemption"), and their only non-test consumers are `cli` (Layer 6, downward-safe from any layer). The upward import is allowlisted with a one-line rationale in both `bin/check-package-layers` (`$kernelExemptFiles`) and `packages/foundation/tests/Unit/LayerDependencyTest.php` (`SRC_SCAN_EXEMPT_FILES`) — keep the two lists in sync.

This was revisited (not re-decided) during the WP6 foundation layer-gate scope work (#1855–#1863 batch): `HealthChecker` spans both `entity` (`EntityTypeManagerInterface`, `EntityTypeInterface`, `FieldDefinitionRegistryInterface`) and `field` (`FieldStorage`) concerns, so unlike the `CacheConfigResolver` L0→L4 relocation (#1807/#1836, one obvious destination package already required by every consumer), there is no single non-arbitrary Layer-1 home to relocate it to in one PR. Splitting `Diagnostic/` across packages (enum/DTOs/interface staying in foundation, impl classes moving) was judged a larger design call than a drift-cleanup PR should make unilaterally — see the file-scanning discussion above for the current allowlist shape.

## Diagnostic Codes

### Boot-time Codes

| Code | Severity | Trigger |
|------|----------|---------|
| `DEFAULT_TYPE_MISSING` | error | No entity types registered at boot |
| `DEFAULT_TYPE_DISABLED` | error | All registered entity types are disabled |
| `UNAUTHORIZED_V1_TAG` | warning | v1.0 git tag without owner approval sentinel |
| `TAG_QUARANTINE_DETECTED` | warning | Existing unauthorized v1.0 tags in repo |
| `MANIFEST_VERSIONING_MISSING` | warning | Defaults manifest lacks project_versioning block |
| `NAMESPACE_RESERVED` | warning | Extension tried to register a `core.` prefixed type |

### Runtime Health Codes

| Code | Severity | Trigger |
|------|----------|---------|
| `DATABASE_UNREACHABLE` | error | SQLite file missing, corrupt, or inaccessible |
| `DATABASE_SCHEMA_DRIFT` | error | Table columns don't match expected entity type definition |
| `MISSING_BUNDLE_SUBTABLE` | error | Bundle has registered fields but `{base_table}__{bundle}` subtable does not exist |
| `MISSING_BUNDLE_UNIQUE_KEY` | error | A declared bundle unique index is absent, non-unique, or targets the wrong ordered columns |
| `ORPHAN_BUNDLE_SUBTABLE` | warning | `{base_table}__{bundle}` subtable exists but no registered bundle carries fields for it |
| `FK_ENFORCEMENT_DISABLED` | error | SQLite `PRAGMA foreign_keys` is OFF — subtable CASCADE deletes will not propagate |
| `CACHE_DIRECTORY_UNWRITABLE` | warning | `storage/framework/` exists but not writable |
| `STORAGE_DIRECTORY_MISSING` | warning | `storage/framework/` does not exist |
| `INGESTION_LOG_OVERSIZED` | warning | Ingestion log exceeds 10,000 entries |
| `INGESTION_RECENT_FAILURES` | warning | >25% of ingestion attempts rejected |
| `COLUMN_DATA_STORAGE_DRIFT` | warning | A field registered with `FieldStorage::Data` still has a backing column on the base table or a registered bundle subtable. New writes go to `_data`; the column holds stale values. Author a migration to drop the column or revert the storage hint. |
| `SCHEMA_DRIFT_CHECK_SKIPPED` | warning | Every registered entity table is lazily uninitialized (none exist yet), so schema drift could not actually be verified for any of them. Distinct from the `DATABASE_SCHEMA_DRIFT` pass state — see "Schema Drift Detection" below. |
| `CLEAN_URL_ROUTING_UNREACHABLE` | error | With `diagnostics.clean_url_probe_url` configured, the known `/.well-known/waaseyaa/clean-url` route does not return the framework sentinel through the public web server. This catches DirectoryIndex-only deployments where `/` works but clean URLs never reach `public/index.php`. |

### Code Structure

Each `DiagnosticCode` case provides:
- `defaultMessage()` — human-readable description of the problem
- `remediation()` — actionable steps to resolve the issue
- `severity()` — `error` or `warning` for health check display

## Health Check Pipeline

`HealthChecker::runAll()` executes checks in three groups:

### 1. Boot Checks

- **Entity types** — at least one entity type must be registered and enabled
- Source: `BootDiagnosticReport` snapshot from kernel boot

### 2. Runtime Checks

- **Database** — `SELECT 1` connectivity test via `DBALDatabase::query()`
- **Schema drift** — compares `PRAGMA table_info()` against `SqlSchemaHandler.buildTableSpec()` expected columns for each registered entity type. Multi-bundle types additionally enumerate their `{base_table}__{bundle}` subtables (see below). Table/subtable identifiers passed into `PRAGMA table_info(...)` are quoted via `DatabaseInterface::quoteIdentifier()` (not hand-built `"..."` string interpolation) — the same identifier-quoting hardening pattern as database-legacy #1816, since an entity type id / bundle name is not guaranteed to be free of quote or metacharacter content.
- **Column-vs-data storage drift** — for each entity type with a registered `FieldStorage::Data` field, probes `PRAGMA table_info()` for a column whose name matches the field on the base table (core fields) or the matching `{base_table}__{bundle}` subtable (bundle fields). Emits `COLUMN_DATA_STORAGE_DRIFT` per occurrence. Skipped silently when no `FieldDefinitionRegistry` is injected. SQLite-only at present — orphan detection went portable in #1301; column-data drift's PRAGMA path is tracked separately for dialect parity.
- **Foreign key enforcement** — SQLite only: checks `PRAGMA foreign_keys`; emits `FK_ENFORCEMENT_DISABLED` when off. Skipped for dialects with default-on enforcement.
- **Storage directory** — `storage/framework/` existence check
- **Cache directory** — `storage/framework/` writability check
- **Clean URL routing** — when `diagnostics.clean_url_probe_url` is non-empty, requests the skeleton's known non-root sentinel route through the public web server. Any connection failure, non-200 response, or missing sentinel is a hard failure with the Apache/nginx/Caddy front-controller remediation.

### 3. Ingestion Checks

- **Log size** — warns if ingestion.jsonl exceeds 10,000 entries
- **Error rate** — warns if >25% of entries are `rejected`
- Source: `IngestionLogger::read()` from `storage/framework/ingestion.jsonl`

## Schema Drift Detection

Compares actual SQLite table schema against expected definition for each entity type.

For multi-bundle entity types with registered bundle-scoped fields, drift detection additionally enumerates `{base_table}__{bundle}` subtables. See [`bundle-scoped-storage.md`](./bundle-scoped-storage.md#drift-diagnostic) for the per-subtable drift contract, missing-subtable, unique-key, and `ORPHAN_BUNDLE_SUBTABLE` codes.

### Algorithm

1. Iterate all registered entity types via `EntityTypeManagerInterface::getDefinitions()`
2. Skip types whose table doesn't exist yet (lazy creation, not drift) — tracked per run as a skip count
3. For each existing table, run `PRAGMA table_info({quoted table})` to get actual columns
4. Build expected columns from `EntityTypeInterface::getKeys()`:
   - Content entities (has `uuid` key): ID column = `INTEGER` (serial), plus `uuid` = `TEXT`
   - Config entities (no `uuid` key): ID column = `TEXT` (varchar)
   - Common columns: bundle, label, langcode — all `TEXT`
   - `_data` (`TEXT`) is expected **only** when the entity type's resolved primary storage backend is `sql-blob` (the framework default, used when `EntityTypeInterface::getPrimaryStorageBackend()` is unset/empty). A `sql-column` entity type materialises every field as a dedicated column and never gets a `_data` blob — `HealthChecker` mirrors the exact backend resolution `Waaseyaa\EntityStorage\EntitySchemaSync::resolveBackend()` performs (declared backend wins, else `sql-blob`), rather than re-deriving the rule independently, so the expected-schema shape can never drift from what `SqlSchemaHandler::buildTableSpec()` actually materialises (#2682).
5. Compare actual vs expected: check for missing columns, type mismatches
6. SQLite type normalization: `varchar` / `varchar(n)` → `TEXT`, `serial` → `INTEGER` (affinity rules)

**Reporting the all-skipped case honestly.** If at least one registered entity type exists, no drift was found among the tables that *were* checked, but every single registered table was skipped as lazily-uninitialized (skip count equals the number of registered definitions), `checkSchemaDrift()` does **not** emit a blanket "Schema drift: pass" — that would claim a comparison happened when none did. Instead it emits a single `SCHEMA_DRIFT_CHECK_SKIPPED` **warn** result under the name `Schema drift`, with a message naming the skip count (`"Schema drift not verified: all N registered entity table(s) are not yet materialized (lazy creation)."`) and `context.skipped` / `context.total`. Warn (not fail) because an uninitialized table pre-`waaseyaa install` is a legitimate transitional state, not an error — same severity class as `STORAGE_DIRECTORY_MISSING`. A **partial** skip (some tables exist and match, others are still lazy) is unaffected and continues to report a plain pass — only the all-skipped case is ambiguous enough to require a distinct signal.

### Drift Entry Shape

```php
['column' => 'type', 'issue' => 'type mismatch: expected TEXT, got INTEGER']
['column' => 'uuid', 'issue' => 'missing']
```

### Bundle Subtable Drift

When `HealthChecker` is constructed with a `FieldDefinitionRegistryInterface`, multi-bundle entity types (those with a `bundleEntityType`) are additionally enumerated per bundle:

1. For each bundle returned by `bundleNamesFor($entityTypeId)` with non-empty `bundleFieldsFor()`, the expected subtable is `{base_table}__{bundle}`.
2. If the subtable is missing, emit `MISSING_BUNDLE_SUBTABLE` under the name `Schema: {base_table}__{bundle}`. `context.table` carries the subtable name.
3. If the subtable exists, compare its columns against the bundle's registered field names. Missing columns are reported as `DATABASE_SCHEMA_DRIFT` under the subtable name, again with `context.table` set.
4. For each declared bundle storage unique key, inspect the named index. It must be unique and target the exact ordered field list; otherwise report `MISSING_BUNDLE_UNIQUE_KEY` with the entity type, bundle, table, key name, and expected fields in context. The diagnostic is read-only and never creates or repairs the index.
5. Orphan detection enumerates every table via `SchemaInterface::listTableNames()` (Doctrine's `AbstractSchemaManager::listTableNames()` under the hood — portable across SQLite, MySQL, PostgreSQL, and any other DBAL-supported driver) and filters in PHP for entries starting with `{base_table}__`. Any subtable not accounted for by a registered non-empty bundle is reported as `ORPHAN_BUNDLE_SUBTABLE` (warn, informational — auto-drop is never performed; author a cleanup migration). Issue #1301 (deferred mission #1257 WP09) replaced the SQLite-only `sqlite_master` LIKE query with this portable path.

Single-bundle entity types (no `bundleEntityType`) are unchanged — subtable enumeration is skipped entirely.

## CLI Commands

The three diagnostic commands are implemented in `packages/cli/src/Command/` and registered by `CliServiceProvider` via `HasNativeCommandsInterface::nativeCommands()`. The `CliKernel` dispatches them; there is no Symfony Console dependency in the registration or dispatch path. See [`docs/specs/cli-kernel.md`](./cli-kernel.md) for the provider contract and kernel lifecycle.

### `health:check`

Fast, script-friendly health probe.

```
$ waaseyaa health:check
+--------+-------------------+------------------------------------------------------+
| Status | Check             | Message                                              |
+--------+-------------------+------------------------------------------------------+
| PASS   | Entity types      | 12 entity type(s) registered and enabled.            |
| PASS   | Database          | Database is accessible.                              |
| PASS   | Schema drift      | All entity table schemas match expected definitions. |
| PASS   | Storage directory | storage/framework/ exists.                           |
| PASS   | Cache directory   | storage/framework/ is writable.                      |
| PASS   | Ingestion log     | No ingestion entries recorded.                       |
+--------+-------------------+------------------------------------------------------+

All health checks passed.
```

**Options:**
- `--json` — output results as JSON array

**Exit codes:**
- `0` — all checks pass
- `1` — warnings present
- `2` — failures present

Non-passing checks show remediations below the table.

### `schema:check`

Standalone schema drift detection with column-level detail.

```
$ waaseyaa schema:check
OK All entity table schemas match expected definitions.
```

When drift is detected:
```
DRIFT Schema: node_type: Table "node_type" has 1 column(s) with schema drift.
+--------+--------------------------------------------+
| Column | Issue                                      |
+--------+--------------------------------------------+
| type   | type mismatch: expected TEXT, got INTEGER   |
+--------+--------------------------------------------+
  Remediation: Delete the SQLite database and restart to recreate tables.
```

**Options:**
- `--json` — output results as JSON

**Exit codes:**
- `0` — no drift
- `1` — drift detected

### `health:report`

Full diagnostic report for operator review or support tickets.

Sections:
1. **System Information** — PHP version, OS, SAPI, database path, config dir
2. **Health Checks** — same table as `health:check`
3. **Ingestion Summary** — total/accepted/rejected, error rate, top error codes
4. **Remediations** — for any non-passing checks

**Options:**
- `--json` — output as structured JSON
- `--output <file>` — write JSON report to file (requires `--json`)

## HealthCheckResult Value Object

```php
HealthCheckResult::pass('Database', 'DB is accessible.');
HealthCheckResult::warn('Cache', DiagnosticCode::CACHE_DIRECTORY_UNWRITABLE, 'Not writable.');
HealthCheckResult::fail('Database', DiagnosticCode::DATABASE_UNREACHABLE);
```

### Serialized Shape

```json
{
    "name": "Database",
    "status": "fail",
    "code": "DATABASE_UNREACHABLE",
    "message": "The database file is missing, corrupt, or not accessible.",
    "remediation": "Verify the WAASEYAA_DB environment variable...",
    "context": {}
}
```

Pass results omit `code`, `remediation`, and `context` when empty.

## Ingestion Health Summary

Generated by `HealthReportCommand` from `IngestionLogger` data:

| Metric | Description |
|--------|-------------|
| `total_entries` | Total ingestion log entries in retention window |
| `accepted` | Count of accepted envelopes |
| `rejected` | Count of rejected envelopes |
| `error_rate` | Percentage of rejected entries |
| `last_accepted` | Timestamp of most recent accepted entry |
| `last_rejected` | Timestamp of most recent rejected entry |
| `top_error_codes` | Top 5 most frequent error codes |

## File Locations

| File | Purpose |
|------|---------|
| `packages/foundation/src/Diagnostic/` | All diagnostic classes |
| `packages/foundation/tests/Unit/Diagnostic/` | Diagnostic unit tests |
| `packages/cli/src/Command/HealthCheckCommand.php` | health:check CLI |
| `packages/cli/src/Command/HealthReportCommand.php` | health:report CLI |
| `packages/cli/src/Command/SchemaCheckCommand.php` | schema:check CLI |
| `packages/cli/tests/Unit/Command/` | CLI command tests |
| `storage/framework/ingestion.jsonl` | Runtime ingestion log |
| `storage/framework/entity-audit.jsonl` | Entity write audit trail |

## See Also

- [`docs/specs/cli-kernel.md`](./cli-kernel.md) — native CLI kernel architecture, `HasNativeCommandsInterface` provider contract, `CliTester` testing harness, exit-code policy
