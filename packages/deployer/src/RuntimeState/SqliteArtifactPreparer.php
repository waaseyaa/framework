<?php

declare(strict_types=1);

namespace Waaseyaa\Deployer\RuntimeState;

/**
 * Builds a runtime-state-safe SQLite candidate without mutating either input.
 *
 * @api
 */
final readonly class SqliteArtifactPreparer
{
    public function __construct(
        private FrameworkRuntimeTableCatalogue $catalogue,
    ) {}

    /**
     * @param list<string> $applicationArtifactTables
     * @param list<string> $retiredApplicationTables Application-owned tables
     *        intentionally removed from the artifact. A serving copy is
     *        accepted only when it is empty.
     */
    public function prepare(
        string $currentDatabase,
        string $artifactDatabase,
        string $candidateDatabase,
        array $applicationArtifactTables,
        array $retiredApplicationTables = [],
    ): SqliteArtifactReport {
        $this->assertInput($currentDatabase, 'Serving database');
        $this->assertInput($artifactDatabase, 'Artifact database');
        if (file_exists($candidateDatabase) || is_link($candidateDatabase)) {
            throw new \RuntimeException('Candidate database already exists.');
        }
        if (realpath($currentDatabase) === realpath($artifactDatabase)) {
            throw new \RuntimeException('Serving and artifact databases must be different files.');
        }

        $current = $this->open($currentDatabase, readOnly: true);
        $artifact = $this->open($artifactDatabase, readOnly: true);
        $this->assertIntegrity($current, 'Serving database');
        $this->assertIntegrity($artifact, 'Artifact database');

        $definitions = $this->catalogue->definitions();
        $this->assertRetirements($current, $artifact, $definitions, $applicationArtifactTables, $retiredApplicationTables);
        $allowed = array_fill_keys([...$applicationArtifactTables, ...$retiredApplicationTables, ...array_keys($definitions), 'sqlite_sequence'], true);
        $this->assertKnownTables($artifact, $allowed, 'artifact');
        $this->assertKnownTables($current, $allowed, 'serving database');
        foreach ($applicationArtifactTables as $table) {
            if (!$this->tableExists($artifact, $table)) {
                throw new \RuntimeException('Artifact is missing application table: ' . $table);
            }
        }

        if (!copy($artifactDatabase, $candidateDatabase)) {
            throw new \RuntimeException('Could not copy the artifact into a candidate database.');
        }
        if (!hash_equals($this->hash($artifactDatabase), $this->hash($candidateDatabase))) {
            @unlink($candidateDatabase);
            throw new \RuntimeException('Artifact changed while the candidate database was being copied.');
        }
        chmod($candidateDatabase, 0o600);
        $candidate = $this->open($candidateDatabase);
        $candidate->exec('PRAGMA foreign_keys = OFF');
        $evidence = [];

        try {
            $candidate->beginTransaction();
            foreach ($definitions as $definition) {
                if ($definition->policy === RuntimeTablePolicy::Artifact) {
                    continue;
                }
                $hasCurrent = $this->tableExists($current, $definition->name);
                $hasCandidate = $this->tableExists($candidate, $definition->name);
                if (!$hasCurrent && !$hasCandidate) {
                    continue;
                }
                if (!$hasCurrent) {
                    $profile = $this->profile($candidate, $definition->name);
                    if ($profile['rows'] !== 0) {
                        throw new \RuntimeException('Artifact-only runtime table must be empty: ' . $definition->name);
                    }
                    $evidence[$definition->name] = new TableInstallEvidence(
                        $definition->policy,
                        0,
                        self::emptyDigest(),
                        0,
                        self::emptyDigest(),
                    );
                    continue;
                }

                if (!$hasCandidate) {
                    $this->cloneSchema($current, $candidate, $definition->name);
                } else {
                    // Structural comparison: the same schema created by a
                    // different code path (line breaks, quoting, CLOB versus
                    // TEXT, an explicit DEFAULT NULL, an inline primary key)
                    // is the same schema. A real column, constraint, index,
                    // or trigger difference names its first differing part.
                    $this->assertCompatibleSchema($current, $candidate, $definition);
                }

                $before = $this->profile($current, $definition->name);
                if ($definition->policy === RuntimeTablePolicy::IdentityMerge && $this->hasStableIdentity($current, $candidate, $definition)) {
                    $this->mergeIdentityRows($current, $candidate, $definition);
                } else {
                    $this->copyRows(
                        source: $current,
                        target: $candidate,
                        table: $definition->name,
                        replace: $definition->policy === RuntimeTablePolicy::IdentityMerge,
                    );
                }
                $after = $this->profile($candidate, $definition->name);
                if ($definition->policy !== RuntimeTablePolicy::IdentityMerge && $before !== $after) {
                    throw new \RuntimeException('Runtime preservation verification failed for ' . $definition->name);
                }
                $evidence[$definition->name] = new TableInstallEvidence(
                    $definition->policy,
                    $before['rows'],
                    $before['digest'],
                    $after['rows'],
                    $after['digest'],
                );
            }

            $this->assertAccountReferences($candidate, $definitions);
            $candidate->commit();
            $candidate->exec('PRAGMA foreign_keys = ON');
            $foreignKeyFailures = $candidate->query('PRAGMA foreign_key_check')->fetchAll();
            if ($foreignKeyFailures !== []) {
                throw new \RuntimeException('Candidate database has dangling foreign-key references.');
            }
            $this->assertIntegrity($candidate, 'Candidate database');
        } catch (\Throwable $error) {
            if ($candidate->inTransaction()) {
                $candidate->rollBack();
            }
            $candidate = null;
            @unlink($candidateDatabase);
            throw $error;
        }

        ksort($evidence, SORT_STRING);

        return new SqliteArtifactReport(FrameworkRuntimeTableCatalogue::VERSION, $evidence);
    }

    /**
     * @param array<string, RuntimeTableDefinition> $definitions
     * @param list<string> $applicationArtifactTables
     * @param list<string> $retiredApplicationTables
     */
    private function assertRetirements(
        \PDO $current,
        \PDO $artifact,
        array $definitions,
        array $applicationArtifactTables,
        array $retiredApplicationTables,
    ): void {
        $active = array_fill_keys($applicationArtifactTables, true);
        foreach ($retiredApplicationTables as $table) {
            if ($table === 'sqlite_sequence' || isset($active[$table]) || isset($definitions[$table])) {
                throw new \RuntimeException('Retired application table conflicts with active ownership: ' . $table);
            }
            if ($this->tableExists($artifact, $table)) {
                throw new \RuntimeException('Retired application table is still present in the artifact: ' . $table);
            }
            if ($this->tableExists($current, $table) && $this->profile($current, $table)['rows'] !== 0) {
                throw new \RuntimeException('Retired application table is not empty: ' . $table);
            }
        }
    }

    private function assertInput(string $path, string $label): void
    {
        if (!is_file($path) || is_link($path)) {
            throw new \RuntimeException($label . ' must be a regular non-symlink file.');
        }
        foreach (['-wal', '-shm'] as $suffix) {
            $sidecar = $path . $suffix;
            if (is_link($sidecar) || (file_exists($sidecar) && !is_file($sidecar))) {
                throw new \RuntimeException($label . ' has an unsafe SQLite sidecar.');
            }
        }
        $wal = $path . '-wal';
        if (is_file($wal) && filesize($wal) !== 0) {
            throw new \RuntimeException($label . ' has committed frames in WAL; checkpoint and quiesce it before preparation.');
        }
    }

    private function hash(string $path): string
    {
        $hash = hash_file('sha256', $path);
        if (!is_string($hash)) {
            throw new \RuntimeException('Could not hash a SQLite input.');
        }

        return $hash;
    }

    private function open(string $path, bool $readOnly = false): \PDO
    {
        $dsn = $readOnly
            ? 'sqlite:file:' . $path . '?mode=ro&immutable=1'
            : 'sqlite:' . $path;

        return new \PDO($dsn, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
    }

    private function assertIntegrity(\PDO $pdo, string $label): void
    {
        $integrity = (string) $pdo->query('PRAGMA integrity_check')->fetchColumn();
        if ($integrity !== 'ok') {
            throw new \RuntimeException($label . ' failed SQLite integrity_check: ' . $integrity);
        }
    }

    /** @param array<string, true> $allowed */
    private function assertKnownTables(\PDO $pdo, array $allowed, string $label): void
    {
        $unknown = [];
        foreach ($this->tables($pdo) as $table) {
            if (!isset($allowed[$table])) {
                $unknown[] = $table;
            }
        }
        if ($unknown !== []) {
            throw new \RuntimeException('Unknown ' . $label . ' tables: ' . implode(', ', $unknown));
        }
    }

    /** @return list<string> */
    private function tables(\PDO $pdo): array
    {
        return array_values(array_map('strval', $pdo->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name",
        )->fetchAll(\PDO::FETCH_COLUMN)));
    }

    private function tableExists(\PDO $pdo, string $table): bool
    {
        $statement = $this->prepareStatement($pdo, "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        $statement->execute([$table]);

        return $statement->fetchColumn() !== false;
    }

    private function cloneSchema(\PDO $source, \PDO $target, string $table): void
    {
        $statement = $this->prepareStatement($source, "SELECT type, name, sql FROM sqlite_master WHERE (name = ? OR tbl_name = ?) AND type IN ('table', 'index', 'trigger') ORDER BY CASE type WHEN 'table' THEN 0 WHEN 'index' THEN 1 ELSE 2 END, name");
        $statement->execute([$table, $table]);
        $created = false;
        foreach ($statement->fetchAll() as $object) {
            if (!is_string($object['sql'] ?? null) || trim($object['sql']) === '') {
                continue;
            }
            $target->exec($object['sql']);
            $created = $created || $object['type'] === 'table';
        }
        if (!$created) {
            throw new \RuntimeException('Could not clone serving-only runtime schema for ' . $table);
        }
    }

    private function assertCompatibleSchema(\PDO $current, \PDO $candidate, RuntimeTableDefinition $definition): void
    {
        $currentSignature = SqliteSchemaSignature::describe($current, $definition->name);
        $candidateSignature = SqliteSchemaSignature::describe($candidate, $definition->name);
        $difference = SqliteSchemaSignature::firstDifference($currentSignature, $candidateSignature);
        if ($difference === null) {
            return;
        }
        if (!$this->isDeclaredAdditiveTransition($currentSignature, $candidateSignature, $definition)) {
            throw new \RuntimeException('Incompatible runtime schema for ' . $definition->name . ' (' . $difference . ')');
        }
    }

    /**
     * Accept only the declared serving-legacy to artifact-current transition.
     * The signatures are compared after removing exactly the declared columns
     * and indexes, so all existing structure remains covered by the normal
     * fail-closed comparison.
     *
     * @param array<string, mixed> $current
     * @param array<string, mixed> $candidate
     */
    private function isDeclaredAdditiveTransition(array $current, array $candidate, RuntimeTableDefinition $definition): bool
    {
        if ($definition->legacyBaseColumns === [] || $definition->stableIdentityColumn === null || $definition->additiveNullableTextColumns === [] || $definition->additiveUniqueIndexes === []) {
            return false;
        }

        $currentColumns = $current['columns'] ?? [];
        $candidateColumns = $candidate['columns'] ?? [];
        if (!is_array($currentColumns) || !is_array($candidateColumns) || count($candidateColumns) !== count($currentColumns) + count($definition->additiveNullableTextColumns)) {
            return false;
        }
        if (array_column($currentColumns, 'name') !== array_map('strtoupper', $definition->legacyBaseColumns)) {
            return false;
        }
        foreach ($currentColumns as $index => $column) {
            if (($candidateColumns[$index]['name'] ?? null) !== ($column['name'] ?? null)) {
                return false;
            }
        }
        $addedColumns = array_slice($candidateColumns, count($currentColumns));
        $expectedColumns = array_map('strtoupper', $definition->additiveNullableTextColumns);
        if (array_column($addedColumns, 'name') !== $expectedColumns) {
            return false;
        }
        foreach ($addedColumns as $column) {
            if (($column['type'] ?? null) !== 'TEXT'
                || ($column['not_null'] ?? null) !== false
                || ($column['default'] ?? null) !== null
                || ($column['primary_key'] ?? null) !== 0
                || ($column['hidden'] ?? null) !== 0
                || ($column['collate'] ?? null) !== null
                || ($column['generated'] ?? null) !== null
                || ($column['not_null_conflict'] ?? null) !== null
            ) {
                return false;
            }
        }

        $currentIndexes = $current['indexes'] ?? [];
        $candidateIndexes = $candidate['indexes'] ?? [];
        if (!is_array($currentIndexes) || !is_array($candidateIndexes)) {
            return false;
        }
        $expectedIndexes = [];
        foreach ($definition->additiveUniqueIndexes as $name => $column) {
            $expectedIndexes[strtoupper($name)] = strtoupper($column);
        }
        $candidateAdded = [];
        foreach ($candidateIndexes as $index) {
            $name = $index['name'] ?? null;
            if (is_string($name) && isset($expectedIndexes[$name])) {
                $candidateAdded[$name] = $index;
            }
        }
        if (count($candidateAdded) !== count($expectedIndexes)) {
            return false;
        }
        foreach ($expectedIndexes as $name => $column) {
            $index = $candidateAdded[$name];
            $indexColumns = $index['columns'] ?? [];
            if (($index['origin'] ?? null) !== 'c'
                || ($index['unique'] ?? null) !== true
                || ($index['partial'] ?? null) !== false
                || count($indexColumns) !== 1
                || ($indexColumns[0]['name'] ?? null) !== $column
                || ($indexColumns[0]['desc'] ?? null) !== false
                || ($indexColumns[0]['collate'] ?? null) !== 'BINARY'
                || ($index['expressions'] ?? []) !== [$column]
                || ($index['where'] ?? null) !== null
            ) {
                return false;
            }
        }
        $candidateBaseIndexes = array_values(array_filter(
            $candidateIndexes,
            static fn(array $index): bool => !is_string($index['name'] ?? null) || !isset($expectedIndexes[$index['name']]),
        ));
        foreach ($currentIndexes as $index) {
            if (is_string($index['name'] ?? null) && isset($expectedIndexes[$index['name']])) {
                return false;
            }
        }
        $candidateBase = $candidate;
        $candidateBase['columns'] = array_slice($candidateColumns, 0, count($currentColumns));
        $candidateBase['indexes'] = $candidateBaseIndexes;
        $currentBase = $current;
        $currentBase['columns'] = $currentColumns;

        return SqliteSchemaSignature::firstDifference($currentBase, $candidateBase) === null;
    }

    private function hasStableIdentity(\PDO $source, \PDO $target, RuntimeTableDefinition $definition): bool
    {
        if ($definition->stableIdentityColumn === null) {
            return false;
        }

        $sourceColumns = $source->query('PRAGMA table_info(' . $this->quoteIdentifier($definition->name) . ')')->fetchAll();
        $targetColumns = $target->query('PRAGMA table_info(' . $this->quoteIdentifier($definition->name) . ')')->fetchAll();

        return $this->resolveColumnName($sourceColumns, $definition->stableIdentityColumn) !== null
            && $this->resolveColumnName($targetColumns, $definition->stableIdentityColumn) !== null;
    }

    private function mergeIdentityRows(\PDO $source, \PDO $target, RuntimeTableDefinition $definition): void
    {
        $table = $this->quoteIdentifier($definition->name);
        $sourceInfo = $source->query('PRAGMA table_info(' . $table . ')')->fetchAll();
        $primaryKeys = array_values(array_filter($sourceInfo, static fn(array $column): bool => (int) ($column['pk'] ?? 0) > 0));
        if (count($primaryKeys) !== 1 || $definition->stableIdentityColumn === null) {
            throw new \RuntimeException('Stable identity merge requires one primary key and a declared identity column for ' . $definition->name);
        }
        $primaryKey = (string) $primaryKeys[0]['name'];
        $identityColumn = $this->resolveColumnName($sourceInfo, $definition->stableIdentityColumn);
        $targetInfo = $target->query('PRAGMA table_info(' . $table . ')')->fetchAll();
        $targetPrimaryKey = $this->resolveColumnName($targetInfo, $primaryKey);
        $targetIdentityColumn = $this->resolveColumnName($targetInfo, $definition->stableIdentityColumn);
        if ($identityColumn === null || $targetPrimaryKey === null || $targetIdentityColumn === null) {
            throw new \RuntimeException('Stable identity merge could not resolve its declared columns for ' . $definition->name);
        }
        $columns = array_values(array_map(static fn(array $column): string => (string) $column['name'], $sourceInfo));
        $quotedColumns = implode(', ', array_map($this->quoteIdentifier(...), $columns));
        $rows = $source->query("SELECT $quotedColumns FROM $table")->fetchAll();
        $targetRows = $target->query(
            "SELECT {$this->quoteIdentifier($targetPrimaryKey)} AS \"__merge_primary\", {$this->quoteIdentifier($targetIdentityColumn)} AS \"__merge_identity\" FROM $table",
        )->fetchAll();

        $seenPrimary = [];
        $seenIdentity = [];
        foreach ($rows as $row) {
            $primaryValue = $row[$primaryKey];
            $identityValue = $row[$identityColumn];
            if ($identityValue === null) {
                throw new \RuntimeException('Identity merge requires a non-null stable identity in ' . $definition->name);
            }
            $primaryKeyValue = $this->identityValueKey($primaryValue);
            $identityKeyValue = $this->identityValueKey($identityValue);
            if (array_key_exists($primaryKeyValue, $seenPrimary) && !$this->sameIdentityValue($seenPrimary[$primaryKeyValue], $identityValue)) {
                throw new \RuntimeException('Identity merge refuses duplicate primary identity in ' . $definition->name);
            }
            if (array_key_exists($identityKeyValue, $seenIdentity) && !$this->sameIdentityValue($seenIdentity[$identityKeyValue], $primaryValue)) {
                throw new \RuntimeException('Identity merge refuses duplicate stable identity in ' . $definition->name);
            }
            $seenPrimary[$primaryKeyValue] = $identityValue;
            $seenIdentity[$identityKeyValue] = $primaryValue;
            foreach ($targetRows as $targetRow) {
                $samePrimary = $this->sameIdentityValue($targetRow['__merge_primary'], $primaryValue);
                $sameIdentity = $this->sameIdentityValue($targetRow['__merge_identity'], $identityValue);
                if ($samePrimary && !$sameIdentity) {
                    throw new \RuntimeException('Identity merge refuses same uid with different uuid in ' . $definition->name);
                }
                if ($sameIdentity && !$samePrimary) {
                    throw new \RuntimeException('Identity merge refuses same uuid with different uid in ' . $definition->name);
                }
            }
        }

        $delete = $this->prepareStatement($target, "DELETE FROM $table WHERE {$this->quoteIdentifier($targetPrimaryKey)} = ? AND {$this->quoteIdentifier($targetIdentityColumn)} IS ?");
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $insert = $this->prepareStatement($target, "INSERT INTO $table ($quotedColumns) VALUES ($placeholders)");
        foreach ($rows as $row) {
            $delete->execute([$row[$primaryKey], $row[$identityColumn]]);
            $insert->execute(array_map(static fn(string $column): mixed => $row[$column], $columns));
        }
    }

    private function identityValueKey(mixed $value): string
    {
        return serialize($value);
    }

    private function sameIdentityValue(mixed $left, mixed $right): bool
    {
        return $left === $right;
    }

    /** @param list<array<string, mixed>> $columns */
    private function resolveColumnName(array $columns, string $requested): ?string
    {
        foreach ($columns as $column) {
            $name = $column['name'] ?? null;
            if (is_string($name) && strcasecmp($name, $requested) === 0) {
                return $name;
            }
        }

        return null;
    }

    private function copyRows(\PDO $source, \PDO $target, string $table, bool $replace): void
    {
        $quoted = $this->quoteIdentifier($table);
        $columns = array_values(array_map(
            static fn(array $column): string => (string) $column['name'],
            $source->query("PRAGMA table_info($quoted)")->fetchAll(),
        ));
        if ($columns === []) {
            throw new \RuntimeException('Runtime table has no columns: ' . $table);
        }
        if (!$replace) {
            $target->exec("DELETE FROM $quoted");
        }
        $quotedColumns = implode(', ', array_map($this->quoteIdentifier(...), $columns));
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $verb = $replace ? 'INSERT OR REPLACE' : 'INSERT';
        $insert = $this->prepareStatement($target, "$verb INTO $quoted ($quotedColumns) VALUES ($placeholders)");
        foreach ($source->query("SELECT $quotedColumns FROM $quoted") as $row) {
            $insert->execute(array_map(static fn(string $column): mixed => $row[$column], $columns));
        }
    }

    /** @return array{rows:int,digest:string} */
    private function profile(\PDO $pdo, string $table): array
    {
        $quoted = $this->quoteIdentifier($table);
        $columns = array_values(array_map(
            static fn(array $column): string => (string) $column['name'],
            $pdo->query("PRAGMA table_info($quoted)")->fetchAll(),
        ));
        $hash = hash_init('sha256');
        $rows = 0;
        if ($columns !== []) {
            $order = implode(', ', array_map($this->quoteIdentifier(...), $columns));
            foreach ($pdo->query("SELECT * FROM $quoted ORDER BY $order") as $row) {
                ++$rows;
                foreach ($columns as $column) {
                    $value = $row[$column];
                    $encoded = match (true) {
                        $value === null => 'n:',
                        is_int($value) => 'i:' . $value,
                        is_float($value) => 'f:' . sprintf('%.17g', $value),
                        default => 's:' . base64_encode((string) $value),
                    };
                    hash_update($hash, strlen($column) . ':' . $column . strlen($encoded) . ':' . $encoded);
                }
                hash_update($hash, "\n");
            }
        }

        return ['rows' => $rows, 'digest' => hash_final($hash)];
    }

    /** @param array<string, RuntimeTableDefinition> $definitions */
    private function assertAccountReferences(\PDO $candidate, array $definitions): void
    {
        if (!$this->tableExists($candidate, 'user')) {
            foreach ($definitions as $definition) {
                if ($definition->accountReferenceColumns !== [] && $this->tableExists($candidate, $definition->name)) {
                    throw new \RuntimeException('Runtime account references require the user identity table.');
                }
            }

            return;
        }
        foreach ($definitions as $definition) {
            if ($definition->accountReferenceColumns === [] || !$this->tableExists($candidate, $definition->name)) {
                continue;
            }
            $columns = array_column(
                $candidate->query('PRAGMA table_info(' . $this->quoteIdentifier($definition->name) . ')')->fetchAll(),
                'name',
            );
            foreach ($definition->accountReferenceColumns as $column) {
                if (!in_array($column, $columns, true)) {
                    continue;
                }
                $quotedTable = $this->quoteIdentifier($definition->name);
                $quotedColumn = $this->quoteIdentifier($column);
                foreach ($candidate->query("SELECT DISTINCT $quotedColumn FROM $quotedTable WHERE $quotedColumn IS NOT NULL") as $row) {
                    $value = $row[$column];
                    $numeric = is_int($value) || (is_string($value) && ctype_digit($value));
                    if ($numeric && in_array((int) $value, $definition->allowedAccountReferenceValues, true)) {
                        continue;
                    }
                    if (!$numeric || (int) $value < 1) {
                        throw new \RuntimeException($definition->name . '.' . $column . ' has a malformed account reference.');
                    }
                    $exists = $this->prepareStatement($candidate, 'SELECT 1 FROM "user" WHERE "uid" = ?');
                    $exists->execute([(int) $value]);
                    if ($exists->fetchColumn() === false) {
                        throw new \RuntimeException(sprintf(
                            '%s.%s references missing user %d',
                            $definition->name,
                            $column,
                            (int) $value,
                        ));
                    }
                }
            }
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    private function prepareStatement(\PDO $pdo, string $sql): \PDOStatement
    {
        $statement = $pdo->prepare($sql);
        if ($statement === false) {
            throw new \RuntimeException('Could not prepare a SQLite statement.');
        }

        return $statement;
    }

    private static function emptyDigest(): string
    {
        return hash('sha256', '');
    }
}
