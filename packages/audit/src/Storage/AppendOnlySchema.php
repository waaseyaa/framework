<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Storage;

use Waaseyaa\Database\SchemaInterface;

/**
 * Schema decorator that refuses destructive DDL (DROP TABLE, DROP COLUMN,
 * DROP INDEX) against append-only tables, while passing all additive DDL and
 * read-only operations through to the inner schema unchanged.
 *
 * Used by {@see AppendOnlyAuditDatabase::schema()} to close the schema
 * bypass: without this wrapper, `->schema()->dropTable('audit_event')` reached
 * the inner schema untouched, circumventing every other arm of the append-only
 * guard (FR-003). Additive DDL (e.g. `addField`, `addIndex`) on append-only
 * tables is still permitted so legitimate migrations can extend the audit table.
 *
 * @api
 */
final class AppendOnlySchema implements SchemaInterface
{
    /**
     * @param list<string> $appendOnlyTables
     */
    public function __construct(
        private readonly SchemaInterface $inner,
        private readonly array $appendOnlyTables,
    ) {}

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
        $this->inner->createTable($name, $spec);
    }

    public function dropTable(string $table): void
    {
        $this->assertNotAppendOnly($table, 'DROP TABLE');
        $this->inner->dropTable($table);
    }

    public function addField(string $table, string $field, array $spec): void
    {
        $this->inner->addField($table, $field, $spec);
    }

    public function dropField(string $table, string $field): void
    {
        $this->assertNotAppendOnly($table, 'DROP COLUMN');
        $this->inner->dropField($table, $field);
    }

    public function addIndex(string $table, string $name, array $fields): void
    {
        $this->inner->addIndex($table, $name, $fields);
    }

    public function dropIndex(string $table, string $name): void
    {
        $this->assertNotAppendOnly($table, 'DROP INDEX');
        $this->inner->dropIndex($table, $name);
    }

    public function addUniqueKey(string $table, string $name, array $fields): void
    {
        $this->inner->addUniqueKey($table, $name, $fields);
    }

    public function addPrimaryKey(string $table, array $fields): void
    {
        $this->inner->addPrimaryKey($table, $fields);
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

    private function assertNotAppendOnly(string $table, string $operation): void
    {
        if (in_array($table, $this->appendOnlyTables, true)) {
            throw new \LogicException(sprintf(
                'Audit table "%s" is append-only (OCAP FR-003): %s is forbidden through the audit '
                . 'database. Records may only be appended; bulk retention deletion goes through '
                . 'audit:prune via the raw DatabaseInterface.',
                $table,
                $operation,
            ));
        }
    }
}
