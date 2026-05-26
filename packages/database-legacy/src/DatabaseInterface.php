<?php

declare(strict_types=1);

namespace Waaseyaa\Database;

interface DatabaseInterface
{
    public function select(string $table, string $alias = ''): SelectInterface;

    public function insert(string $table): InsertInterface;

    public function update(string $table): UpdateInterface;

    public function delete(string $table): DeleteInterface;

    public function schema(): SchemaInterface;

    public function transaction(string $name = ''): TransactionInterface;

    /** @return \Traversable */
    public function query(string $sql, array $args = []): \Traversable;

    /**
     * Quote a SQL identifier (table or column name) in the platform dialect.
     *
     * Callers must pass the raw unquoted identifier. Implementations decide
     * the appropriate quote character (for example, double-quote on SQLite
     * and PostgreSQL; backtick on MySQL).
     */
    public function quoteIdentifier(string $identifier): string;
}
