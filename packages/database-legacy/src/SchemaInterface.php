<?php

declare(strict_types=1);

namespace Waaseyaa\Database;

/**
 * @api
 */
interface SchemaInterface
{
    public function tableExists(string $table): bool;

    public function fieldExists(string $table, string $field): bool;

    public function createTable(string $name, array $spec): void;

    public function dropTable(string $table): void;

    public function addField(string $table, string $field, array $spec): void;

    public function dropField(string $table, string $field): void;

    public function addIndex(string $table, string $name, array $fields): void;

    public function dropIndex(string $table, string $name): void;

    public function addUniqueKey(string $table, string $name, array $fields): void;

    public function addPrimaryKey(string $table, array $fields): void;

    /**
     * Enumerate every table visible to the current connection.
     *
     * Portable across SQLite, MySQL, PostgreSQL and any other dialect the
     * underlying driver supports — callers must not assume a specific
     * backend. Used by diagnostics that scan the live schema (e.g. orphan
     * bundle-subtable detection in `HealthChecker`).
     *
     * @return list<string> Unordered list of table names. Empty when the
     *                      connection has no tables.
     */
    public function listTableNames(): array;
}
