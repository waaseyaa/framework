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

        // #3149: capture and precondition-check the artifact's schema
        // authority manifest from this read-only connection, before the
        // candidate file exists. Reconciliation later binds the copied
        // candidate to exactly these captured values, not to a later re-read
        // of $artifact.
        $artifactSchemaAuthority = $this->captureArtifactSchemaAuthority($artifact);

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
            $this->reconcileSchemaAuthority($candidate, $definitions, $artifactSchemaAuthority);
            $candidate->commit();
            $candidate->exec('PRAGMA foreign_keys = ON');
            $foreignKeyFailures = $candidate->query('PRAGMA foreign_key_check')->fetchAll();
            if ($foreignKeyFailures !== []) {
                throw new \RuntimeException('Candidate database has dangling foreign-key references.');
            }
            $this->assertIntegrity($candidate, 'Candidate database');
            $this->assertSchemaAuthorityVerified($candidate);
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

    /**
     * #3149: `waaseyaa_schema_authority` is {@see RuntimeTablePolicy::Artifact},
     * so the candidate carries the artifact's manifest untouched even though
     * runtime preservation just changed its actual logical schema (cloned
     * serving-only tables, merged identities). Left alone, the next
     * code-only deployment's `MigrationRepository::assertSchemaAuthorityPreState()`
     * fails closed with `[S1-DB109]` against a candidate that is not
     * actually wrong — see #2548's 2026-09-22/23 comments.
     *
     * Called from `prepare()` with a connection still open read-only against
     * the artifact database, before the candidate file exists, so a stale
     * manifest fails before a single row moves (Rule 1). Returns `null` when
     * the artifact carries no fingerprinted manifest at all (Rule 5: a fresh
     * install or a #2452 adoption is left untouched, matching
     * {@see \Waaseyaa\Foundation\Migration\MigrationRepository::assertSchemaAuthorityPreState()}'s
     * own no-manifest pass-through) — otherwise the captured values
     * `reconcileSchemaAuthority()` later binds the copied candidate to.
     *
     * @return array{schema_fingerprint:string, ledger_fingerprint:string, source_catalog_fingerprint:?string, generation:int, schema_objects:list<array{type:string,name:string,table:string,sql:?string}>}|null
     */
    private function captureArtifactSchemaAuthority(\PDO $artifact): ?array
    {
        $manifest = $this->schemaAuthorityManifest($artifact);
        if ($manifest === null || $manifest['schema_fingerprint'] === null || $manifest['ledger_fingerprint'] === null) {
            return null;
        }

        // Rule 1 — precondition: the artifact's own manifest must already be
        // self-consistent. A stale artifact manifest must never be blessed by
        // this reconciliation; it must fail before a single row moves.
        if (!hash_equals(SchemaAuthorityFingerprint::logicalSchemaFingerprint($artifact), $manifest['schema_fingerprint'])
            || !hash_equals(SchemaAuthorityFingerprint::ledgerFingerprint($artifact), $manifest['ledger_fingerprint'])
        ) {
            throw new \RuntimeException(
                'Artifact schema authority manifest is stale: its recorded schema_fingerprint or ledger_fingerprint does not match its own computed schema and ledger.',
            );
        }

        return [
            'schema_fingerprint' => $manifest['schema_fingerprint'],
            'ledger_fingerprint' => $manifest['ledger_fingerprint'],
            'source_catalog_fingerprint' => $manifest['source_catalog_fingerprint'],
            'generation' => $manifest['generation'],
            'schema_objects' => SchemaAuthorityFingerprint::schemaObjects($artifact),
        ];
    }

    /**
     * Runs inside the caller's still-open candidate transaction, after
     * runtime preservation and before commit. `$artifactSchemaAuthority` is
     * `captureArtifactSchemaAuthority()`'s pre-copy snapshot; `null` means
     * Rule 5 (no manifest) and this is a no-op.
     *
     * A cloned serving trigger can fire during row copying and mutate
     * `waaseyaa_schema_authority` (or any other Artifact-policy table's
     * data) in the candidate before this method runs — `cloneSchema()`
     * matches on `name = ? OR tbl_name = ?`, so a serving trigger merely
     * *named* after a preserved table, regardless of which table it is
     * actually `ON`, is cloned too, and a trigger genuinely `ON` a preserved
     * table can write anywhere. The bind check below catches a mutated
     * manifest row directly (review probe b); {@see assertSchemaAuthorityVerified()}
     * catches a mutated `waaseyaa_migrations` row after commit, because a
     * data-only change to another Artifact-policy table has no schema-object
     * footprint for {@see assertBoundedSchemaDifference()} to see (review
     * probe c) — see `docs/change-records/FW-3149.md` for why that residual
     * is recorded for #2548 rather than fixed here.
     *
     * @param array<string, RuntimeTableDefinition> $definitions
     * @param array{schema_fingerprint:string, ledger_fingerprint:string, source_catalog_fingerprint:?string, generation:int, schema_objects:list<array{type:string,name:string,table:string,sql:?string}>}|null $artifactSchemaAuthority
     */
    private function reconcileSchemaAuthority(\PDO $candidate, array $definitions, ?array $artifactSchemaAuthority): void
    {
        if ($artifactSchemaAuthority === null) {
            return;
        }

        // Review item 7 — bind: the candidate's own copied manifest row must
        // still equal exactly what was captured from the artifact before the
        // copy, in every column, including `generation`. This is what
        // catches probe (b): a cloned trigger `ON` the preserved table that
        // UPDATEs `waaseyaa_schema_authority` during row copying — whether it
        // mutates a fingerprint column, `source_catalog_fingerprint`, or
        // `generation` — leaves a candidate row that no longer matches the
        // artifact this reconciliation is supposed to be reconciling.
        $candidateManifest = $this->schemaAuthorityManifest($candidate);
        if ($candidateManifest === null
            || $candidateManifest['schema_fingerprint'] !== $artifactSchemaAuthority['schema_fingerprint']
            || $candidateManifest['ledger_fingerprint'] !== $artifactSchemaAuthority['ledger_fingerprint']
            || $candidateManifest['source_catalog_fingerprint'] !== $artifactSchemaAuthority['source_catalog_fingerprint']
            || $candidateManifest['generation'] !== $artifactSchemaAuthority['generation']
        ) {
            throw new \RuntimeException(
                'Candidate schema authority manifest no longer matches the values captured from the artifact before preparation began.',
            );
        }

        // Rule 2 — bounded difference: every schema object that differs
        // between the artifact (as captured before the copy) and the
        // prepared candidate must belong to a catalogue table whose policy is
        // not Artifact (runtime preservation is the only thing that may have
        // changed the schema at this point; this is verified independently
        // rather than trusted from the loop above). This is what catches
        // probe (a): a serving trigger named after a preserved table but
        // actually `ON` an Artifact-policy or application-owned table.
        $this->assertBoundedSchemaDifference($artifactSchemaAuthority['schema_objects'], $candidate, $definitions);

        // Rule 3 — re-record: only schema_fingerprint moves, conditioned on
        // it still being exactly the value the artifact's manifest named.
        // ledger_fingerprint, source_catalog_fingerprint and generation stay
        // whatever the artifact copy already carries: waaseyaa_migrations is
        // itself RuntimeTablePolicy::Artifact and untouched by this handoff,
        // so the candidate's ledger is byte-identical to the artifact's and
        // needs no re-recording; generation counts governed transitions, not
        // artifact handoffs, so it is left exactly as the artifact recorded
        // it.
        $candidateSchemaFingerprint = SchemaAuthorityFingerprint::logicalSchemaFingerprint($candidate);
        $statement = $this->prepareStatement(
            $candidate,
            'UPDATE waaseyaa_schema_authority SET schema_fingerprint = ? WHERE authority_id = 1 AND schema_fingerprint = ?',
        );
        $statement->execute([$candidateSchemaFingerprint, $artifactSchemaAuthority['schema_fingerprint']]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException(
                'Schema authority manifest changed concurrently; refusing to re-record the candidate schema fingerprint.',
            );
        }
    }

    /**
     * Diffs the artifact's captured `sqlite_schema` objects against the
     * candidate's current ones (not just trusts that the preservation loop
     * above only ever touches non-Artifact tables) and requires every
     * differing object's owning table to be a catalogue table whose policy is
     * not Artifact. The declared `user` additive transition (#3127) needs no
     * special case: its policy is already IdentityMerge, not Artifact.
     *
     * @param list<array{type:string,name:string,table:string,sql:?string}> $artifactSchemaObjects
     * @param array<string, RuntimeTableDefinition> $definitions
     */
    private function assertBoundedSchemaDifference(array $artifactSchemaObjects, \PDO $candidate, array $definitions): void
    {
        $key = static fn(array $object): string => implode("\0", [$object['type'], $object['name'], $object['table'], $object['sql'] ?? "\0null"]);

        $before = [];
        foreach ($artifactSchemaObjects as $object) {
            $before[$key($object)] = $object;
        }
        $after = [];
        foreach (SchemaAuthorityFingerprint::schemaObjects($candidate) as $object) {
            $after[$key($object)] = $object;
        }

        $changedTables = [];
        foreach ($after as $signature => $object) {
            if (!isset($before[$signature])) {
                $changedTables[$object['table']] = true;
            }
        }
        foreach ($before as $signature => $object) {
            if (!isset($after[$signature])) {
                $changedTables[$object['table']] = true;
            }
        }

        foreach (array_keys($changedTables) as $table) {
            $definition = $definitions[$table] ?? null;
            if ($definition === null || $definition->policy === RuntimeTablePolicy::Artifact) {
                throw new \RuntimeException(
                    'Schema authority reconciliation found an unbounded schema difference outside runtime-policy tables: ' . $table,
                );
            }
        }
    }

    /**
     * After commit: prove the candidate's recorded manifest equals its own
     * computed values, using the identical computation
     * {@see \Waaseyaa\Foundation\Migration\MigrationRepository::assertSchemaAuthorityPreState()}
     * and `migrate --verify` use, so refusal here and refusal there can never
     * disagree (Rule 4). The caller's catch block deletes the candidate file
     * on any exception from this method.
     */
    private function assertSchemaAuthorityVerified(\PDO $candidate): void
    {
        $manifest = $this->schemaAuthorityManifest($candidate);
        if ($manifest === null || $manifest['schema_fingerprint'] === null || $manifest['ledger_fingerprint'] === null) {
            return;
        }

        if (!hash_equals(SchemaAuthorityFingerprint::logicalSchemaFingerprint($candidate), $manifest['schema_fingerprint'])
            || !hash_equals(SchemaAuthorityFingerprint::ledgerFingerprint($candidate), $manifest['ledger_fingerprint'])
        ) {
            throw new \RuntimeException(
                '[S1-DB109] Candidate schema authority verification failed after preparation; the prepared candidate has been discarded.',
            );
        }
    }

    /**
     * A missing table means "fresh install or a #2452 adoption" and is a
     * legitimate no-manifest skip (Rule 5). A present table missing a
     * required column is a different thing — an installation too old for
     * this contract — and must not be silently treated the same way; mirrors
     * {@see \Waaseyaa\Foundation\Migration\MigrationRepository::schemaAuthorityManifest()}'s
     * own `[S1-DB105]` refusal for the identical case.
     *
     * @return array{schema_fingerprint:?string, ledger_fingerprint:?string, source_catalog_fingerprint:?string, generation:int}|null
     */
    private function schemaAuthorityManifest(\PDO $pdo): ?array
    {
        if (!$this->tableExists($pdo, 'waaseyaa_schema_authority')) {
            return null;
        }
        $columns = array_column($pdo->query('PRAGMA table_info(waaseyaa_schema_authority)')->fetchAll(), 'name');
        $required = ['schema_fingerprint', 'ledger_fingerprint', 'source_catalog_fingerprint', 'generation'];
        $missing = array_values(array_diff($required, $columns));
        if ($missing !== []) {
            throw new \RuntimeException(sprintf(
                '[S1-DB105] Schema authority manifest requires coordinator upgrade; missing columns: %s.',
                implode(', ', $missing),
            ));
        }
        $row = $pdo->query(
            'SELECT schema_fingerprint, ledger_fingerprint, source_catalog_fingerprint, generation FROM waaseyaa_schema_authority WHERE authority_id = 1',
        )->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'schema_fingerprint' => is_string($row['schema_fingerprint']) ? $row['schema_fingerprint'] : null,
            'ledger_fingerprint' => is_string($row['ledger_fingerprint']) ? $row['ledger_fingerprint'] : null,
            'source_catalog_fingerprint' => is_string($row['source_catalog_fingerprint']) ? $row['source_catalog_fingerprint'] : null,
            'generation' => (int) $row['generation'],
        ];
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
