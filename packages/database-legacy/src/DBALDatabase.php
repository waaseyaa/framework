<?php

declare(strict_types=1);

namespace Waaseyaa\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Waaseyaa\Database\Query\DBALDelete;
use Waaseyaa\Database\Query\DBALInsert;
use Waaseyaa\Database\Query\DBALSelect;
use Waaseyaa\Database\Query\DBALUpdate;
use Waaseyaa\Database\Schema\DBALSchema;

final class DBALDatabase implements DatabaseInterface
{
    public function __construct(
        private readonly Connection $connection,
    ) {}

    public static function createSqlite(string $path = ':memory:'): self
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $path === ':memory:' ? null : $path,
            'memory' => $path === ':memory:',
        ]);

        // Enable WAL mode for better concurrent read performance.
        if ($path !== ':memory:') {
            $connection->executeStatement('PRAGMA journal_mode = WAL');
        }

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
     * This replaces PdoDatabase::getPdo(). Consumers that previously
     * used raw PDO should migrate to DBAL's Connection API.
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }
}
