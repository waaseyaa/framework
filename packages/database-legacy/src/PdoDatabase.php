<?php

declare(strict_types=1);

namespace Aurora\Database;

use Aurora\Database\Query\PdoDelete;
use Aurora\Database\Query\PdoInsert;
use Aurora\Database\Query\PdoSelect;
use Aurora\Database\Query\PdoUpdate;
use Aurora\Database\Schema\PdoSchema;

final class PdoDatabase implements DatabaseInterface
{
    public function __construct(
        private readonly \PDO $pdo,
    ) {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public static function createSqlite(string $path = ':memory:'): self
    {
        $pdo = new \PDO('sqlite:' . $path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return new self($pdo);
    }

    public function select(string $table, string $alias = ''): SelectInterface
    {
        return new PdoSelect($this->pdo, $table, $alias);
    }

    public function insert(string $table): InsertInterface
    {
        return new PdoInsert($this->pdo, $table);
    }

    public function update(string $table): UpdateInterface
    {
        return new PdoUpdate($this->pdo, $table);
    }

    public function delete(string $table): DeleteInterface
    {
        return new PdoDelete($this->pdo, $table);
    }

    public function schema(): SchemaInterface
    {
        return new PdoSchema($this->pdo);
    }

    public function transaction(string $name = ''): TransactionInterface
    {
        return new PdoTransaction($this->pdo);
    }

    public function query(string $sql, array $args = []): \Traversable
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($args);

        return $stmt;
    }

    /**
     * Returns the underlying PDO connection.
     */
    public function getPdo(): \PDO
    {
        return $this->pdo;
    }
}
