<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Diagnostic;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Field\FieldStorage;
use Waaseyaa\Foundation\Ingestion\IngestionLogger;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Runs all operator health checks and returns structured results.
 *
 * Checks are organized in three groups:
 *   1. Boot checks — entity type registry state
 *   2. Runtime checks — database, cache, storage directories
 *   3. Ingestion checks — log health and error rates
 */
final class HealthChecker implements HealthCheckerInterface
{
    /** Error rate threshold (percentage) that triggers a warning. */
    private const float ERROR_RATE_WARN_THRESHOLD = 25.0;

    /** Maximum ingestion log entries before warning (roughly 10k entries). */
    private const int LOG_SIZE_WARN_THRESHOLD = 10000;

    private readonly LoggerInterface $logger;
    private readonly IngestionLogger $ingestionLogger;

    public function __construct(
        private readonly BootDiagnosticReport $bootReport,
        private readonly DatabaseInterface $database,
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly string $projectRoot,
        ?LoggerInterface $logger = null,
        private readonly ?FieldDefinitionRegistryInterface $fieldRegistry = null,
        ?IngestionLogger $ingestionLogger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        // PHP 8.4 can't call `new IngestionLogger($projectRoot)` as a parameter
        // default (constructor promotion doesn't see the other params yet), so
        // resolve the nullable default in the body — see CLAUDE.md "PHP 8.4
        // parameter defaults can't call static methods" gotcha (same shape).
        $this->ingestionLogger = $ingestionLogger ?? new IngestionLogger($projectRoot);
    }

    /** @return list<HealthCheckResult> */
    public function runAll(): array
    {
        return [
            ...$this->checkBoot(),
            ...$this->checkRuntime(),
            ...$this->checkIngestion(),
        ];
    }

    /** @return list<HealthCheckResult> */
    public function checkBoot(): array
    {
        $results = [];

        if ($this->bootReport->hasEnabledTypes()) {
            $enabled = $this->bootReport->enabledTypeIds();
            $results[] = HealthCheckResult::pass(
                'Entity types',
                sprintf('%d entity type(s) registered and enabled.', count($enabled)),
            );
        } else {
            $registered = array_keys($this->bootReport->registeredTypes);
            if ($registered === []) {
                $results[] = HealthCheckResult::fail(
                    'Entity types',
                    DiagnosticCode::DEFAULT_TYPE_MISSING,
                );
            } else {
                $results[] = HealthCheckResult::fail(
                    'Entity types',
                    DiagnosticCode::DEFAULT_TYPE_DISABLED,
                    context: ['disabled' => $this->bootReport->disabledTypeIds],
                );
            }
        }

        return $results;
    }

    /** @return list<HealthCheckResult> */
    public function checkRuntime(): array
    {
        $results = [];

        // Database connectivity.
        $results[] = $this->checkDatabase();

        // Schema drift.
        $driftResults = $this->checkSchemaDrift();
        array_push($results, ...$driftResults);

        // Column-vs-data storage drift (FieldStorage::Data fields with a
        // lingering column on disk).
        $columnDataDrift = $this->checkColumnDataStorageDrift();
        array_push($results, ...$columnDataDrift);

        // Storage directory.
        $results[] = $this->checkStorageDirectory();

        // Cache directory.
        $results[] = $this->checkCacheDirectory();

        // SQLite foreign-key enforcement (skipped for non-SQLite drivers).
        $fk = $this->checkForeignKeysEnabled();
        if ($fk !== null) {
            $results[] = $fk;
        }

        return $results;
    }

    /** @return list<HealthCheckResult> */
    public function checkIngestion(): array
    {
        $results = [];

        $entries = $this->ingestionLogger->read();
        $total = count($entries);

        if ($total === 0) {
            $results[] = HealthCheckResult::pass('Ingestion log', 'No ingestion entries recorded.');
            return $results;
        }

        // Log size check.
        if ($total > self::LOG_SIZE_WARN_THRESHOLD) {
            $results[] = HealthCheckResult::warn(
                'Ingestion log size',
                DiagnosticCode::INGESTION_LOG_OVERSIZED,
                sprintf('Ingestion log contains %d entries (threshold: %d). Consider pruning.', $total, self::LOG_SIZE_WARN_THRESHOLD),
                context: ['entry_count' => $total, 'threshold' => self::LOG_SIZE_WARN_THRESHOLD],
            );
        } else {
            $results[] = HealthCheckResult::pass(
                'Ingestion log size',
                sprintf('%d entries within threshold.', $total),
            );
        }

        // Error rate check.
        $rejected = 0;
        foreach ($entries as $entry) {
            if (($entry['status'] ?? '') === 'rejected') {
                $rejected++;
            }
        }

        $errorRate = ($total > 0) ? ($rejected / $total) * 100.0 : 0.0;

        if ($errorRate > self::ERROR_RATE_WARN_THRESHOLD) {
            $results[] = HealthCheckResult::warn(
                'Ingestion error rate',
                DiagnosticCode::INGESTION_RECENT_FAILURES,
                sprintf('%.1f%% of ingestion attempts failed (%d/%d rejected).', $errorRate, $rejected, $total),
                context: ['rejected' => $rejected, 'total' => $total, 'error_rate' => round($errorRate, 1)],
            );
        } else {
            $results[] = HealthCheckResult::pass(
                'Ingestion error rate',
                sprintf('%.1f%% error rate (%d/%d rejected).', $errorRate, $rejected, $total),
            );
        }

        return $results;
    }

    private function checkDatabase(): HealthCheckResult
    {
        try {
            $this->database->query('SELECT 1', []);

            return HealthCheckResult::pass('Database', 'Database is accessible.');
        } catch (\Throwable $e) {
            return HealthCheckResult::fail(
                'Database',
                DiagnosticCode::DATABASE_UNREACHABLE,
                'Database is not accessible: ' . $e->getMessage(),
            );
        }
    }

    /** @return list<HealthCheckResult> */
    public function checkSchemaDrift(): array
    {
        $results = [];
        $definitions = $this->entityTypeManager->getDefinitions();
        $schema = $this->database->schema();
        $driftFound = false;
        $skippedCount = 0;

        foreach ($definitions as $id => $type) {
            $tableName = $id;

            if (!$schema->tableExists($tableName)) {
                // Table doesn't exist yet (lazy creation) — not drift, just uninitialized.
                $skippedCount++;
                $this->logger->info(sprintf('Schema drift: skipping %s — table %s does not exist (lazy creation)', $id, $tableName));
                continue;
            }

            $driftEntries = $this->detectTableDrift($type, $tableName);

            if ($driftEntries !== []) {
                $driftFound = true;
                $results[] = HealthCheckResult::fail(
                    "Schema: {$id}",
                    DiagnosticCode::DATABASE_SCHEMA_DRIFT,
                    sprintf('Table "%s" has %d column(s) with schema drift.', $tableName, count($driftEntries)),
                    context: ['table' => $tableName, 'drift' => $driftEntries],
                );
            }

            // Bundle subtable drift — only for multi-bundle entity types when a
            // FieldDefinitionRegistry was supplied. See bundle-scoped-storage.md
            // §Drift diagnostic.
            if ($this->fieldRegistry !== null && $type->getBundleEntityType() !== null) {
                foreach ($this->checkBundleSubtables($type) as $subtableResult) {
                    if ($subtableResult->status === 'fail') {
                        $driftFound = true;
                    }
                    $results[] = $subtableResult;
                }
            }
        }

        if (!$driftFound && $results === []) {
            if ($definitions !== [] && $skippedCount === count($definitions)) {
                // Every registered table was lazily uninitialized — nothing was
                // actually compared. A blanket "pass" here would be dishonest;
                // report the skip explicitly instead (warn, not fail — an
                // uninitialized table is a legitimate pre-boot state, not an
                // error, matching the per-table "not drift" log line above).
                $results[] = HealthCheckResult::warn(
                    'Schema drift',
                    DiagnosticCode::SCHEMA_DRIFT_CHECK_SKIPPED,
                    sprintf(
                        'Schema drift not verified: all %d registered entity table(s) are not yet materialized (lazy creation).',
                        $skippedCount,
                    ),
                    context: ['skipped' => $skippedCount, 'total' => count($definitions)],
                );
            } else {
                $results[] = HealthCheckResult::pass('Schema drift', 'All entity table schemas match expected definitions.');
            }
        }

        return $results;
    }

    /**
     * Detect column-vs-_data storage drift: fields registered with
     * `FieldStorage::Data` whose name still has a backing column on the base
     * table or a registered bundle subtable. Such columns hold stale data
     * (new writes go to the `_data` JSON blob) and reads silently skip them
     * once query routing is symmetric.
     *
     * Skipped silently when no FieldDefinitionRegistry was injected — the
     * registry is the source of truth for the storage hint.
     *
     * @return list<HealthCheckResult>
     */
    public function checkColumnDataStorageDrift(): array
    {
        if ($this->fieldRegistry === null) {
            return [];
        }

        $results = [];
        $schema = $this->database->schema();

        foreach (array_keys($this->entityTypeManager->getDefinitions()) as $entityId) {
            $baseTable = $entityId;

            // Core fields land on the base table.
            if ($schema->tableExists($baseTable)) {
                foreach ($this->fieldRegistry->coreFieldsFor($entityId) as $field) {
                    if (!$this->fieldIsDataStored($field)) {
                        continue;
                    }
                    $fieldName = $field->getName();
                    if ($this->columnExists($baseTable, $fieldName)) {
                        $results[] = $this->columnDataDriftResult($entityId, $baseTable, $fieldName, bundle: null);
                    }
                }
            }

            // Bundle fields land on the per-bundle subtable.
            foreach ($this->fieldRegistry->bundleNamesFor($entityId) as $bundle) {
                $subtable = $baseTable . '__' . $bundle;
                if (!$schema->tableExists($subtable)) {
                    continue;
                }

                foreach ($this->fieldRegistry->bundleFieldsFor($entityId, $bundle) as $field) {
                    if (!$this->fieldIsDataStored($field)) {
                        continue;
                    }
                    $fieldName = $field->getName();
                    if ($this->columnExists($subtable, $fieldName)) {
                        $results[] = $this->columnDataDriftResult($entityId, $subtable, $fieldName, bundle: $bundle);
                    }
                }
            }
        }

        return $results;
    }

    private function fieldIsDataStored(object $field): bool
    {
        return method_exists($field, 'getStored') && $field->getStored() === FieldStorage::Data;
    }

    /**
     * SQLite-only PRAGMA column existence probe. On non-SQLite drivers the
     * query throws; treat that as "no drift" and skip silently — portable
     * column enumeration is tracked separately under #1301.
     */
    private function columnExists(string $tableName, string $columnName): bool
    {
        try {
            $quotedTable = $this->database->quoteIdentifier($tableName);
            foreach ($this->database->query("PRAGMA table_info({$quotedTable})", []) as $row) {
                if (($row['name'] ?? null) === $columnName) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->info(sprintf(
                'Column-data drift detection skipped for %s.%s: %s',
                $tableName,
                $columnName,
                $e->getMessage(),
            ));
        }
        return false;
    }

    private function columnDataDriftResult(string $entityId, string $tableName, string $columnName, ?string $bundle): HealthCheckResult
    {
        $context = [
            'entity_type' => $entityId,
            'table' => $tableName,
            'column' => $columnName,
            'field' => $columnName,
        ];
        if ($bundle !== null) {
            $context['bundle'] = $bundle;
        }

        return HealthCheckResult::warn(
            "Storage drift: {$tableName}.{$columnName}",
            DiagnosticCode::COLUMN_DATA_STORAGE_DRIFT,
            sprintf(
                'Field "%s" on entity type "%s" is registered with FieldStorage::Data but column "%s" still exists on table "%s". New writes go to _data; the column holds stale data.',
                $columnName,
                $entityId,
                $columnName,
                $tableName,
            ),
            context: $context,
        );
    }

    /**
     * Enumerate per-bundle subtables for a multi-bundle entity type and report
     * missing subtables, column drift, and orphan subtables.
     *
     * @return list<HealthCheckResult>
     */
    private function checkBundleSubtables(EntityTypeInterface $type): array
    {
        \assert($this->fieldRegistry !== null);
        $results = [];
        $entityId = $type->id();
        $baseTable = $entityId;
        $schema = $this->database->schema();
        $expectedSubtables = [];

        foreach ($this->fieldRegistry->bundleNamesFor($entityId) as $bundle) {
            $fields = $this->fieldRegistry->bundleFieldsFor($entityId, $bundle);
            if ($fields === []) {
                continue;
            }

            $subtableName = $baseTable . '__' . $bundle;
            $expectedSubtables[$subtableName] = true;

            if (!$schema->tableExists($subtableName)) {
                $results[] = HealthCheckResult::fail(
                    "Schema: {$subtableName}",
                    DiagnosticCode::MISSING_BUNDLE_SUBTABLE,
                    sprintf(
                        'Bundle "%s" has %d registered field(s) but subtable "%s" does not exist.',
                        $bundle,
                        count($fields),
                        $subtableName,
                    ),
                    context: [
                        'table' => $subtableName,
                        'bundle' => $bundle,
                        'entity_type' => $entityId,
                        'field_count' => count($fields),
                    ],
                );
                continue;
            }

            $drift = $this->detectSubtableColumnDrift($subtableName, array_keys($fields));
            if ($drift !== []) {
                $results[] = HealthCheckResult::fail(
                    "Schema: {$subtableName}",
                    DiagnosticCode::DATABASE_SCHEMA_DRIFT,
                    sprintf('Subtable "%s" has %d column(s) with schema drift.', $subtableName, count($drift)),
                    context: [
                        'table' => $subtableName,
                        'bundle' => $bundle,
                        'entity_type' => $entityId,
                        'drift' => $drift,
                    ],
                );
            }
        }

        foreach ($this->findOrphanSubtables($baseTable, $expectedSubtables) as $orphan) {
            $results[] = HealthCheckResult::warn(
                "Schema: {$orphan}",
                DiagnosticCode::ORPHAN_BUNDLE_SUBTABLE,
                sprintf(
                    'Subtable "%s" exists but no registered bundle of "%s" carries fields for it.',
                    $orphan,
                    $entityId,
                ),
                context: ['table' => $orphan, 'entity_type' => $entityId],
            );
        }

        return $results;
    }

    /**
     * @param list<string> $expectedFieldNames
     * @return list<array{column: string, issue: string}>
     */
    private function detectSubtableColumnDrift(string $subtableName, array $expectedFieldNames): array
    {
        $actualColumns = [];
        $quotedSubtable = $this->database->quoteIdentifier($subtableName);
        foreach ($this->database->query("PRAGMA table_info({$quotedSubtable})", []) as $row) {
            $actualColumns[$row['name']] = true;
        }

        $drift = [];
        foreach ($expectedFieldNames as $fieldName) {
            if (!isset($actualColumns[$fieldName])) {
                $drift[] = ['column' => $fieldName, 'issue' => 'missing'];
            }
        }
        return $drift;
    }

    /**
     * Enumerate `{baseTable}__*` tables that the registry does not expect.
     *
     * Issue #1301 (deferred mission #1257 WP09): replaced the SQLite-only
     * `sqlite_master` query with `SchemaInterface::listTableNames()`, which
     * Doctrine implements portably across SQLite, MySQL, PostgreSQL, etc.
     * Filtering by `{baseTable}__` prefix happens in PHP — `str_starts_with`
     * has no LIKE-pattern escaping concerns. The `__` between base and
     * bundle is reserved (see bundle-scoped-storage.md §Naming) so prefix
     * match is unambiguous.
     *
     * @param array<string, true> $expectedSubtables
     * @return list<string>
     */
    private function findOrphanSubtables(string $baseTable, array $expectedSubtables): array
    {
        $prefix = $baseTable . '__';
        $orphans = [];

        foreach ($this->database->schema()->listTableNames() as $name) {
            if (!str_starts_with($name, $prefix)) {
                continue;
            }
            if (isset($expectedSubtables[$name])) {
                continue;
            }
            $orphans[] = $name;
        }

        return $orphans;
    }

    /**
     * SQLite-only PRAGMA check. Returns null for non-SQLite drivers or when
     * the pragma value is unavailable — no noise for default-ON dialects.
     */
    private function checkForeignKeysEnabled(): ?HealthCheckResult
    {
        try {
            $rows = iterator_to_array($this->database->query('PRAGMA foreign_keys', []), false);
        } catch (\Throwable) {
            return null;
        }

        if ($rows === []) {
            return null;
        }

        $value = $rows[0]['foreign_keys'] ?? null;
        if ($value === null) {
            return null;
        }

        if ((int) $value === 1) {
            return HealthCheckResult::pass(
                'Foreign key enforcement',
                'SQLite PRAGMA foreign_keys = ON.',
            );
        }

        return HealthCheckResult::fail(
            'Foreign key enforcement',
            DiagnosticCode::FK_ENFORCEMENT_DISABLED,
            'SQLite PRAGMA foreign_keys = OFF. Per-bundle subtable CASCADE deletes will not fire.',
        );
    }

    /**
     * Compare actual table columns against what
     * Waaseyaa\EntityStorage\SqlSchemaHandler::buildTableSpec() expects.
     *
     * @return list<array{column: string, issue: string}>
     */
    private function detectTableDrift(EntityTypeInterface $type, string $tableName): array
    {
        $drift = [];

        // Get actual columns from SQLite PRAGMA.
        $actualColumns = [];
        $quotedTable = $this->database->quoteIdentifier($tableName);
        foreach ($this->database->query("PRAGMA table_info({$quotedTable})", []) as $row) {
            $actualColumns[$row['name']] = [
                'type' => strtoupper($row['type']),
                'notnull' => (bool) $row['notnull'],
                'pk' => (bool) $row['pk'],
            ];
        }

        // Build expected columns from entity type keys.
        $keys = $type->getKeys();
        $expectedColumns = $this->buildExpectedColumns($keys);

        // Check for missing expected columns.
        foreach ($expectedColumns as $col => $spec) {
            if (!isset($actualColumns[$col])) {
                $drift[] = ['column' => $col, 'issue' => 'missing'];
                continue;
            }

            // Check type match (SQLite normalizes types).
            $actualType = $actualColumns[$col]['type'];
            $expectedType = $spec['expected_type'];

            if ($actualType !== $expectedType) {
                // SQLite stores varchar as TEXT and serial as INTEGER — both are valid.
                if (!$this->typesCompatible($expectedType, $actualType)) {
                    $drift[] = [
                        'column' => $col,
                        'issue' => sprintf('type mismatch: expected %s, got %s', $expectedType, $actualType),
                    ];
                }
            }

            // Check PK for ID column.
            if ($col === ($keys['id'] ?? 'id') && $spec['is_pk'] && !$actualColumns[$col]['pk']) {
                $drift[] = ['column' => $col, 'issue' => 'expected primary key but column is not PK'];
            }
        }

        return $drift;
    }

    /**
     * @return array<string, array{expected_type: string, is_pk: bool}>
     */
    private function buildExpectedColumns(array $keys): array
    {
        $columns = [];
        $idKey = $keys['id'] ?? 'id';
        $hasUuid = isset($keys['uuid']);

        // ID column.
        $columns[$idKey] = [
            'expected_type' => $hasUuid ? 'INTEGER' : 'TEXT',
            'is_pk' => true,
        ];

        // UUID column (content entities).
        if ($hasUuid) {
            $columns[$keys['uuid']] = ['expected_type' => 'TEXT', 'is_pk' => false];
        }

        // Bundle.
        $bundleKey = $keys['bundle'] ?? 'bundle';
        $columns[$bundleKey] = ['expected_type' => 'TEXT', 'is_pk' => false];

        // Label.
        $labelKey = $keys['label'] ?? 'label';
        $columns[$labelKey] = ['expected_type' => 'TEXT', 'is_pk' => false];

        // Langcode.
        $langcodeKey = $keys['langcode'] ?? 'langcode';
        $columns[$langcodeKey] = ['expected_type' => 'TEXT', 'is_pk' => false];

        // _data blob.
        $columns['_data'] = ['expected_type' => 'TEXT', 'is_pk' => false];

        return $columns;
    }

    private function typesCompatible(string $expected, string $actual): bool
    {
        // SQLite gives VARCHAR, including parameterized VARCHAR(n), TEXT affinity.
        $actual = preg_replace('/^VARCHAR\s*\(\s*\d+\s*\)$/i', 'VARCHAR', $actual) ?? $actual;

        // SQLite normalizes varchar→TEXT, serial→INTEGER, int→INTEGER.
        // DBAL may produce CLOB instead of TEXT for string columns.
        $normalMap = [
            'TEXT' => 'TEXT',
            'VARCHAR' => 'TEXT',
            'CLOB' => 'TEXT',
            'INTEGER' => 'INTEGER',
            'SERIAL' => 'INTEGER',
            'REAL' => 'REAL',
            'BLOB' => 'BLOB',
        ];

        $normExpected = $normalMap[strtoupper($expected)] ?? strtoupper($expected);
        $normActual = $normalMap[strtoupper($actual)] ?? strtoupper($actual);

        return $normExpected === $normActual;
    }

    private function checkStorageDirectory(): HealthCheckResult
    {
        $dir = $this->projectRoot . '/storage/framework';

        if (is_dir($dir)) {
            return HealthCheckResult::pass('Storage directory', 'storage/framework/ exists.');
        }

        return HealthCheckResult::warn(
            'Storage directory',
            DiagnosticCode::STORAGE_DIRECTORY_MISSING,
            'storage/framework/ directory does not exist.',
        );
    }

    private function checkCacheDirectory(): HealthCheckResult
    {
        $dir = $this->projectRoot . '/storage/framework';

        if (!is_dir($dir)) {
            // Already reported by storage directory check.
            return HealthCheckResult::pass('Cache directory', 'Skipped (storage directory missing).');
        }

        if (is_writable($dir)) {
            return HealthCheckResult::pass('Cache directory', 'storage/framework/ is writable.');
        }

        return HealthCheckResult::warn(
            'Cache directory',
            DiagnosticCode::CACHE_DIRECTORY_UNWRITABLE,
            'storage/framework/ is not writable.',
        );
    }
}
