<?php

declare(strict_types=1);

namespace Waaseyaa\Database;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Waaseyaa\Database\Query\DBALDelete;
use Waaseyaa\Database\Query\DBALInsert;
use Waaseyaa\Database\Query\DBALSelect;
use Waaseyaa\Database\Query\DBALUpdate;
use Waaseyaa\Database\Schema\DBALSchema;

final class DBALDatabase implements ConsistentReadDatabaseInterface, DatabaseIdentityProviderInterface
{
    public function __construct(
        private readonly Connection $connection,
    ) {
        $this->installVirtualTableSchemaAssetsFilter();
    }

    /**
     * Hide SQLite virtual tables (FTS5 &c.), their shadow tables, and SQLite
     * internals from DBAL schema introspection (#2056).
     *
     * FTS5 tables expose typeless columns that DBAL's SQLiteSchemaManager
     * cannot parse, so any full-catalog introspection (`DBALSchema::addField()`
     * and every other diff-based schema op) crashed kernel boot once a search
     * index existed in the database. Virtual/shadow tables are not manageable
     * through DBAL anyway — raw SQL (`sqlite_master`) is the only correct
     * interface, which is what the search package uses. Consequence: these
     * tables are also invisible to `tableExists()`/`listTableNames()`, by
     * design.
     *
     * The exclusion set is snapshot-cached per instance; an asset name the
     * snapshot has never seen triggers one refresh, so a virtual table created
     * mid-process (long-running FrankenPHP workers; the search indexer creates
     * its table lazily) is still filtered on the next schema operation.
     * Platform detection happens inside the filter — by the time DBAL invokes
     * it a live connection exists, so lazy non-SQLite connections stay lazy.
     */
    private function installVirtualTableSchemaAssetsFilter(): void
    {
        $snapshot = null;
        $this->connection->getConfiguration()->setSchemaAssetsFilter(
            function (mixed $asset) use (&$snapshot): bool {
                if (!$this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
                    return true;
                }

                $name = \is_string($asset) ? $asset : (string) $asset;
                if (str_starts_with($name, 'sqlite_')) {
                    return false;
                }

                $snapshot ??= $this->virtualTableSnapshot();
                if (!\in_array($name, $snapshot['known'], true)) {
                    // A table the snapshot predates — refresh once so newly
                    // created virtual tables are classified correctly.
                    $snapshot = $this->virtualTableSnapshot();
                }

                return !\in_array($name, $snapshot['excluded'], true);
            },
        );
    }

    /**
     * Classify the catalog via `pragma_table_list` (SQLite ≥ 3.37): rows typed
     * `virtual`/`shadow` are excluded from DBAL introspection. Falls back to a
     * `sqlite_master` scan (virtual tables by their CREATE statement, shadows
     * by the `<virtual>_<suffix>` naming SQLite reserves for them) on builds
     * without the pragma.
     *
     * @return array{known: list<string>, excluded: list<string>}
     */
    private function virtualTableSnapshot(): array
    {
        $known = [];
        $excluded = [];

        try {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT name, type FROM pragma_table_list WHERE schema = 'main'",
            );
            foreach ($rows as $row) {
                $name = (string) $row['name'];
                $known[] = $name;
                if (\in_array($row['type'], ['virtual', 'shadow'], true)) {
                    $excluded[] = $name;
                }
            }

            return ['known' => $known, 'excluded' => $excluded];
        } catch (\Throwable) {
            // SQLite < 3.37 — fall through to the sqlite_master heuristic.
        }

        $virtual = [];
        $rows = $this->connection->fetchAllAssociative(
            "SELECT name, sql FROM sqlite_master WHERE type = 'table'",
        );
        foreach ($rows as $row) {
            $name = (string) $row['name'];
            $known[] = $name;
            if (preg_match('/^\s*CREATE\s+VIRTUAL\s+TABLE/i', (string) ($row['sql'] ?? '')) === 1) {
                $virtual[] = $name;
            }
        }
        foreach ($known as $name) {
            foreach ($virtual as $virtualTable) {
                if ($name === $virtualTable || str_starts_with($name, $virtualTable . '_')) {
                    $excluded[] = $name;
                    break;
                }
            }
        }

        return ['known' => $known, 'excluded' => $excluded];
    }

    public static function createSqlite(string $path = ':memory:', ?string $environment = null): self
    {
        SqliteTopology::assertSupportedPath($path);
        if ($environment !== null) {
            SqliteTopology::assertEnvironmentAllowsPath($path, $environment);
        }

        $configuration = new Configuration();
        $configuration->setMiddlewares([new SqliteDriverMiddleware(fileBacked: $path !== ':memory:')]);

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $path === ':memory:' ? null : $path,
            'memory' => $path === ':memory:',
        ], $configuration);

        SqliteTopology::configureAndVerify($connection, fileBacked: $path !== ':memory:');

        return new self($connection);
    }

    public function select(string $table, string $alias = ''): SelectInterface
    {
        return new DBALSelect($this->connection, $table, $alias);
    }

    public function insert(string $table): InsertInterface
    {
        return new DBALInsert($this->connection, $table);
    }

    public function update(string $table): UpdateInterface
    {
        return new DBALUpdate($this->connection, $table);
    }

    public function delete(string $table): DeleteInterface
    {
        return new DBALDelete($this->connection, $table);
    }

    public function schema(): SchemaInterface
    {
        return new DBALSchema($this->connection);
    }

    public function transaction(string $name = ''): TransactionInterface
    {
        return new DBALTransaction($this->connection);
    }

    public function consistentReadTransaction(string $name = ''): TransactionInterface
    {
        return new DBALConsistentReadTransaction($this->connection);
    }

    public function query(string $sql, array $args = []): \Traversable
    {
        $trimmed = ltrim($sql);
        $spacePos = strpos($trimmed, ' ');
        $firstWord = strtoupper($spacePos !== false ? substr($trimmed, 0, $spacePos) : $trimmed);

        // Non-SELECT statements (DDL/DML) use executeStatement and return an empty iterator.
        // This must not share a function body with yield, because PHP treats any function
        // containing yield as a generator (lazy execution), which would defer the statement.
        if ($firstWord !== 'SELECT' && $firstWord !== 'PRAGMA') {
            $this->connection->executeStatement($sql, $args);

            return new \EmptyIterator();
        }

        return $this->executeSelectQuery($sql, $args);
    }

    /**
     * @param list<mixed> $args
     */
    private function executeSelectQuery(string $sql, array $args): \Generator
    {
        $result = $this->connection->executeQuery($sql, $args);

        // Yield associative rows to match PdoDatabase behavior (FETCH_ASSOC).
        while ($row = $result->fetchAssociative()) {
            yield $row;
        }
    }

    /**
     * Returns the underlying DBAL Connection.
     *
     * Deliberate migration escape hatch replacing PdoDatabase::getPdo(). It
     * bypasses this package's identifier/value safety guarantees; ordinary
     * reads and writes must use the fluent DatabaseInterface builders.
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }

    public function databaseIdentity(): string
    {
        $params = $this->connection->getParams();
        $path = $params['path'] ?? null;
        if (is_string($path) && $path !== '') {
            $real = realpath($path);
            $path = str_replace('\\', '/', $real !== false ? $real : $path);
        }

        $canonical = json_encode([
            'schema' => 'database-identity.v1',
            'driver' => $params['driver'] ?? $params['driverClass'] ?? 'unknown',
            'path' => $path,
            'memory' => $params['memory'] ?? false,
            'host' => $params['host'] ?? null,
            'port' => $params['port'] ?? null,
            'dbname' => $params['dbname'] ?? null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return 'database:v1:' . hash('sha256', $canonical);
    }

    public function quoteIdentifier(string $identifier): string
    {
        return $this->connection->quoteIdentifier($identifier);
    }
}
