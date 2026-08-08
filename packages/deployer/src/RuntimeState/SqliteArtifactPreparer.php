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
     */
    public function prepare(
        string $currentDatabase,
        string $artifactDatabase,
        string $candidateDatabase,
        array $applicationArtifactTables,
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
        $allowed = array_fill_keys([...$applicationArtifactTables, ...array_keys($definitions), 'sqlite_sequence'], true);
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
                } elseif ($this->schemaSignature($current, $definition->name) !== $this->schemaSignature($candidate, $definition->name)) {
                    throw new \RuntimeException('Incompatible runtime schema for ' . $definition->name);
                }

                $before = $this->profile($current, $definition->name);
                $this->copyRows(
                    source: $current,
                    target: $candidate,
                    table: $definition->name,
                    replace: $definition->policy === RuntimeTablePolicy::IdentityMerge,
                );
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

    private function assertInput(string $path, string $label): void
    {
        if (!is_file($path) || is_link($path)) {
            throw new \RuntimeException($label . ' must be a regular non-symlink file.');
        }
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

    private function schemaSignature(\PDO $pdo, string $table): string
    {
        $quoted = $this->quoteIdentifier($table);
        $parts = [
            'table_sql' => $this->schemaSql($pdo, 'table', $table),
            'columns' => $pdo->query("PRAGMA table_xinfo($quoted)")->fetchAll(),
            'foreign_keys' => $pdo->query("PRAGMA foreign_key_list($quoted)")->fetchAll(),
            'indexes' => [],
            'triggers' => [],
        ];
        foreach ($pdo->query("PRAGMA index_list($quoted)")->fetchAll() as $index) {
            $name = (string) $index['name'];
            $parts['indexes'][] = [
                'unique' => (int) $index['unique'],
                'origin' => (string) $index['origin'],
                'partial' => (int) $index['partial'],
                'columns' => $pdo->query('PRAGMA index_xinfo(' . $this->quoteIdentifier($name) . ')')->fetchAll(),
            ];
        }
        $trigger = $this->prepareStatement($pdo, "SELECT sql FROM sqlite_master WHERE type = 'trigger' AND tbl_name = ? ORDER BY name");
        $trigger->execute([$table]);
        $parts['triggers'] = $trigger->fetchAll(\PDO::FETCH_COLUMN);

        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }

    private function schemaSql(\PDO $pdo, string $type, string $name): string
    {
        $statement = $this->prepareStatement($pdo, 'SELECT sql FROM sqlite_master WHERE type = ? AND name = ?');
        $statement->execute([$type, $name]);
        $sql = $statement->fetchColumn();
        if (!is_string($sql) || trim($sql) === '') {
            throw new \RuntimeException(sprintf('Missing %s schema SQL for %s', $type, $name));
        }

        return preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
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
