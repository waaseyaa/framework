<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Storage;

use Waaseyaa\Database\SchemaInterface;

/**
 * Runtime schema decorator that permits inspection and refuses every DDL call.
 *
 * Used by {@see AppendOnlyAuditDatabase::schema()} to close the schema
 * bypass: without this wrapper, DDL reached the inner schema untouched,
 * circumventing the migration coordinator and the append-only guard (FR-003).
 * Legitimate migrations use the coordinator's raw connection.
 *
 * @api
 */
final class AppendOnlySchema implements SchemaInterface
{
    /**
     * @param list<string> $appendOnlyTables Retained for constructor compatibility;
     *                                      runtime DDL is now refused for every table.
     */
    public function __construct(
        private readonly SchemaInterface $inner,
        array $appendOnlyTables,
    ) {
        unset($appendOnlyTables);
    }

    public function tableExists(string $table): bool
    {
        return $this->inner->tableExists($table);
    }

    public function fieldExists(string $table, string $field): bool
    {
        return $this->inner->fieldExists($table, $field);
    }

    public function createTable(string $name, array $spec): void
    {
        $this->refuse($name, 'CREATE TABLE');
    }

    public function dropTable(string $table): void
    {
        $this->refuse($table, 'DROP TABLE');
    }

    public function addField(string $table, string $field, array $spec): void
    {
        $this->refuse($table, 'ADD COLUMN');
    }

    public function dropField(string $table, string $field): void
    {
        $this->refuse($table, 'DROP COLUMN');
    }

    public function addIndex(string $table, string $name, array $fields): void
    {
        $this->refuse($table, 'CREATE INDEX');
    }

    public function dropIndex(string $table, string $name): void
    {
        $this->refuse($table, 'DROP INDEX');
    }

    public function addUniqueKey(string $table, string $name, array $fields): void
    {
        $this->refuse($table, 'CREATE UNIQUE INDEX');
    }

    public function addPrimaryKey(string $table, array $fields): void
    {
        $this->refuse($table, 'ADD PRIMARY KEY');
    }

    /**
     * Enumerate every table visible to the current connection.
     *
     * @return list<string> Unordered list of table names. Empty when the
     *                      connection has no tables.
     */
    public function listTableNames(): array
    {
        return $this->inner->listTableNames();
    }

    private function refuse(string $table, string $operation): never
    {
        throw new \LogicException(sprintf(
            'Runtime schema mutation is coordinator-only: %s on table "%s" is forbidden through the audit database. '
                . 'Migrations use the coordinator connection; bulk retention deletion goes through audit:prune via the raw '
                . 'DatabaseInterface.',
            $operation,
            $table,
        ));
    }
}
