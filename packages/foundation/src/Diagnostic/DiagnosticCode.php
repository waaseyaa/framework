<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Diagnostic;

/**
 * Canonical operator-facing diagnostic error codes.
 *
 * Each case documents the trigger condition, default human message, and
 * remediation steps. When a code fires, DiagnosticEmitter logs a structured
 * entry so operators can correlate errors across distributed systems.
 * @api
 */
enum DiagnosticCode: string
{
    // --- Boot-time codes ---
    case DEFAULT_TYPE_MISSING        = 'DEFAULT_TYPE_MISSING';
    case DEFAULT_TYPE_DISABLED       = 'DEFAULT_TYPE_DISABLED';
    case UNAUTHORIZED_V1_TAG         = 'UNAUTHORIZED_V1_TAG';
    case TAG_QUARANTINE_DETECTED     = 'TAG_QUARANTINE_DETECTED';
    case MANIFEST_VERSIONING_MISSING = 'MANIFEST_VERSIONING_MISSING';
    case NAMESPACE_RESERVED          = 'NAMESPACE_RESERVED';

    // --- Runtime health codes ---
    case DATABASE_UNREACHABLE        = 'DATABASE_UNREACHABLE';
    case DATABASE_SCHEMA_DRIFT       = 'DATABASE_SCHEMA_DRIFT';
    case MISSING_BUNDLE_SUBTABLE     = 'MISSING_BUNDLE_SUBTABLE';
    case ORPHAN_BUNDLE_SUBTABLE      = 'ORPHAN_BUNDLE_SUBTABLE';
    case FK_ENFORCEMENT_DISABLED     = 'FK_ENFORCEMENT_DISABLED';
    case CACHE_DIRECTORY_UNWRITABLE  = 'CACHE_DIRECTORY_UNWRITABLE';
    case STORAGE_DIRECTORY_MISSING   = 'STORAGE_DIRECTORY_MISSING';
    case INGESTION_LOG_OVERSIZED     = 'INGESTION_LOG_OVERSIZED';
    case INGESTION_RECENT_FAILURES   = 'INGESTION_RECENT_FAILURES';
    case COLUMN_DATA_STORAGE_DRIFT   = 'COLUMN_DATA_STORAGE_DRIFT';
    case SCHEMA_DRIFT_CHECK_SKIPPED  = 'SCHEMA_DRIFT_CHECK_SKIPPED';
    case CLEAN_URL_ROUTING_UNREACHABLE = 'CLEAN_URL_ROUTING_UNREACHABLE';

    // --- Schema-evolution v2 codes (mission #529) ---
    case CHECKSUM_MISMATCH           = 'CHECKSUM_MISMATCH';
    case LEDGER_ORPHAN               = 'LEDGER_ORPHAN';
    case MIGRATION_CYCLE             = 'MIGRATION_CYCLE';
    case UNKNOWN_DEPENDENCY          = 'UNKNOWN_DEPENDENCY';
    case INCOMPATIBLE_FLAGS          = 'INCOMPATIBLE_FLAGS';

    public function defaultMessage(): string
    {
        return match ($this) {
            self::DEFAULT_TYPE_MISSING =>
                'No content types are registered. At least one must be available at boot.',
            self::DEFAULT_TYPE_DISABLED =>
                'All registered content types are disabled. At least one must remain enabled.',
            self::UNAUTHORIZED_V1_TAG =>
                'A v1.0 tag was created without the required owner approval sentinel file.',
            self::TAG_QUARANTINE_DETECTED =>
                'Existing unauthorized v1.0 tag(s) detected in the repository.',
            self::MANIFEST_VERSIONING_MISSING =>
                'A defaults manifest is missing the required project_versioning block.',
            self::NAMESPACE_RESERVED =>
                'The "core." namespace is reserved for built-in platform types and cannot be used by extensions or tenants.',
            self::DATABASE_UNREACHABLE =>
                'The database file is missing, corrupt, or not accessible.',
            self::DATABASE_SCHEMA_DRIFT =>
                'One or more entity table columns do not match the expected schema definition.',
            self::MISSING_BUNDLE_SUBTABLE =>
                'A bundle has registered fields but its per-bundle subtable does not exist in storage.',
            self::ORPHAN_BUNDLE_SUBTABLE =>
                'A per-bundle subtable exists in storage but no registered bundle of that entity type carries fields for it.',
            self::FK_ENFORCEMENT_DISABLED =>
                'SQLite foreign key enforcement is disabled — per-bundle subtable CASCADE deletes will not propagate.',
            self::CACHE_DIRECTORY_UNWRITABLE =>
                'The cache storage directory exists but is not writable by the current process.',
            self::STORAGE_DIRECTORY_MISSING =>
                'The storage/framework/ directory does not exist and could not be created.',
            self::INGESTION_LOG_OVERSIZED =>
                'The ingestion log file exceeds the expected size for the retention window. Run pruning.',
            self::INGESTION_RECENT_FAILURES =>
                'A high proportion of recent ingestion attempts have failed.',
            self::COLUMN_DATA_STORAGE_DRIFT =>
                'A field is registered with FieldStorage::Data but a column for the field still exists in storage. New writes go to the _data JSON blob; the column holds stale values that reads will silently skip once query routing is symmetric.',
            self::SCHEMA_DRIFT_CHECK_SKIPPED =>
                'Schema drift could not be verified because every registered entity table is not yet materialized (lazy creation) — nothing was actually compared.',
            self::CLEAN_URL_ROUTING_UNREACHABLE =>
                'A known non-root URL did not reach the Waaseyaa router through the configured public application URL.',
            self::CHECKSUM_MISMATCH =>
                'A v2 migration was re-applied with a different source checksum than the one recorded in the ledger. Production refuses silent re-apply; the same migration_id cannot mean two different structural intents.',
            self::LEDGER_ORPHAN =>
                'A ledger row references a migration_id whose source could no longer be located in the loaded migration set. Verify mode treats orphans as drift.',
            self::MIGRATION_CYCLE =>
                'The unified migration dependency graph contains a cycle. The Migrator cannot produce a deterministic apply order until the cycle is broken.',
            self::UNKNOWN_DEPENDENCY =>
                'A v2 migration declared a dependency string that resolves to no known migration_id and no known package in the current run.',
            self::INCOMPATIBLE_FLAGS =>
                'The CLI invocation combined flags that cannot be used together (for example, `migrate --dry-run --verify`).',
        };
    }

    public function remediation(): string
    {
        return match ($this) {
            self::DEFAULT_TYPE_MISSING =>
                'Enable core.note (`waaseyaa type:enable note`) or register a custom content type via a service provider.',
            self::DEFAULT_TYPE_DISABLED =>
                'Run `waaseyaa type:enable note` to re-enable the default type, or enable any other registered type.',
            self::UNAUTHORIZED_V1_TAG =>
                'Open a release-quarantine issue on the waaseyaa/framework repository. See VERSIONING.md §2 for the approval process.',
            self::TAG_QUARANTINE_DETECTED =>
                'Follow VERSIONING.md §2 to either approve the tag or delete it. Do not proceed with CI until resolved.',
            self::MANIFEST_VERSIONING_MISSING =>
                'Add a project_versioning block to the manifest per VERSIONING.md §3.',
            self::NAMESPACE_RESERVED =>
                'Use a custom namespace prefix (e.g., myorg.article). The "core." prefix is reserved for platform built-ins.',
            self::DATABASE_UNREACHABLE =>
                'Verify the WAASEYAA_DB environment variable points to a valid SQLite file. Run `waaseyaa install` to initialize.',
            self::DATABASE_SCHEMA_DRIFT =>
                'Delete the SQLite database and restart to recreate tables, or run `waaseyaa schema:check` for details.',
            self::MISSING_BUNDLE_SUBTABLE =>
                'Re-run `waaseyaa install` (or the bundle-scoped migration) to materialize the subtable. Subtable name format: `{base_table}__{bundle}`.',
            self::ORPHAN_BUNDLE_SUBTABLE =>
                'Review whether the bundle was removed intentionally. If so, author a cleanup migration to drop the orphan subtable; auto-drop is never performed.',
            self::FK_ENFORCEMENT_DISABLED =>
                'Ensure the SQLite connection issues `PRAGMA foreign_keys = ON` on every connection. Waaseyaa configures this by default; external tooling may override.',
            self::CACHE_DIRECTORY_UNWRITABLE =>
                'Check file permissions on storage/framework/. The web server user must have write access.',
            self::STORAGE_DIRECTORY_MISSING =>
                'Create the storage/framework/ directory with appropriate permissions: `mkdir -p storage/framework && chmod 755 storage/framework`.',
            self::INGESTION_LOG_OVERSIZED =>
                'Run `waaseyaa health:check` to review log size, then prune old entries or increase the retention window.',
            self::INGESTION_RECENT_FAILURES =>
                'Review recent ingestion errors in storage/framework/ingestion.jsonl. Check envelope format and payload schemas.',
            self::COLUMN_DATA_STORAGE_DRIFT =>
                'Author a migration to drop the lingering column once any data has been backfilled into _data, or revert the FieldStorage::Data hint if the column should remain canonical. Auto-drop is never performed.',
            self::SCHEMA_DRIFT_CHECK_SKIPPED =>
                'Run `waaseyaa install` (or your app bootstrap) to materialize the registered entity tables, then re-run `waaseyaa schema:check` to verify drift.',
            self::CLEAN_URL_ROUTING_UNREACHABLE =>
                'Configure the web root as public/ and forward missing paths to public/index.php. For Apache use `FallbackResource /index.php`; see docs/deployment-web-servers.md for Apache, nginx, and Caddy examples.',
            self::CHECKSUM_MISMATCH =>
                'Either revert the source change so the canonical SchemaDiff matches the stored checksum, or author a new migration_id (Q1 — migration_id is the canonical key).',
            self::LEDGER_ORPHAN =>
                'Investigate whether the migration was deliberately removed (uninstalled package, deleted local file). If so, document the orphan; do not silently delete the ledger row.',
            self::MIGRATION_CYCLE =>
                'Inspect the cycle path in the exception message and remove one of the dependency edges. v2 dependencies and legacy `$after` are both candidates.',
            self::UNKNOWN_DEPENDENCY =>
                'Check the spelling of the dependency string and ensure the dependency package is installed in this composer install.',
            self::INCOMPATIBLE_FLAGS =>
                'Pass only one mode flag at a time: `migrate` (apply), `migrate --dry-run` (preview), or `migrate --verify` (audit).',
        };
    }

    /**
     * Severity level for health check display.
     */
    public function severity(): string
    {
        return match ($this) {
            self::DEFAULT_TYPE_MISSING,
            self::DEFAULT_TYPE_DISABLED,
            self::DATABASE_UNREACHABLE,
            self::DATABASE_SCHEMA_DRIFT,
            self::MISSING_BUNDLE_SUBTABLE,
            self::FK_ENFORCEMENT_DISABLED => 'error',
            self::CLEAN_URL_ROUTING_UNREACHABLE => 'error',

            self::UNAUTHORIZED_V1_TAG,
            self::TAG_QUARANTINE_DETECTED,
            self::INGESTION_RECENT_FAILURES,
            self::CACHE_DIRECTORY_UNWRITABLE,
            self::ORPHAN_BUNDLE_SUBTABLE,
            self::COLUMN_DATA_STORAGE_DRIFT,
            self::SCHEMA_DRIFT_CHECK_SKIPPED => 'warning',

            self::MANIFEST_VERSIONING_MISSING,
            self::NAMESPACE_RESERVED,
            self::STORAGE_DIRECTORY_MISSING,
            self::INGESTION_LOG_OVERSIZED => 'warning',

            self::CHECKSUM_MISMATCH,
            self::MIGRATION_CYCLE,
            self::UNKNOWN_DEPENDENCY,
            self::INCOMPATIBLE_FLAGS => 'error',

            self::LEDGER_ORPHAN => 'warning',
        };
    }
}
