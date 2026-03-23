<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Diagnostic;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
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

    public function __construct(
        private readonly BootDiagnosticReport $bootReport,
        private readonly DatabaseInterface $database,
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly string $projectRoot,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
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

        // Storage directory.
        $results[] = $this->checkStorageDirectory();

        // Cache directory.
        $results[] = $this->checkCacheDirectory();

        return $results;
    }

    /** @return list<HealthCheckResult> */
    public function checkIngestion(): array
    {
        $results = [];

        $logger = new IngestionLogger($this->projectRoot);
        $entries = $logger->read();
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

        foreach ($definitions as $id => $type) {
            $tableName = $id;

            if (!$schema->tableExists($tableName)) {
                // Table doesn't exist yet (lazy creation) — not drift, just uninitialized.
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
        }

        if (!$driftFound && $results === []) {
            $results[] = HealthCheckResult::pass('Schema drift', 'All entity table schemas match expected definitions.');
        }

        return $results;
    }

    /**
     * Compare actual table columns against what SqlSchemaHandler.buildTableSpec() expects.
     *
     * @return list<array{column: string, issue: string}>
     */
    private function detectTableDrift(EntityTypeInterface $type, string $tableName): array
    {
        $drift = [];

        // Get actual columns from SQLite PRAGMA.
        $actualColumns = [];
        foreach ($this->database->query("PRAGMA table_info(\"{$tableName}\")", []) as $row) {
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
