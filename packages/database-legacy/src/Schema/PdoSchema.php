<?php

declare(strict_types=1);

namespace Aurora\Database\Schema;

use Aurora\Database\SchemaInterface;

final class PdoSchema implements SchemaInterface
{
    public function __construct(
        private readonly \PDO $pdo,
    ) {}

    public function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?"
        );
        $stmt->execute([$table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function fieldExists(string $table, string $field): bool
    {
        if (!$this->tableExists($table)) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'PRAGMA table_info(' . $this->quoteIdentifier($table) . ')'
        );
        $stmt->execute();

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            if ($row['name'] === $field) {
                return true;
            }
        }

        return false;
    }

    public function createTable(string $name, array $spec): void
    {
        if ($this->tableExists($name)) {
            throw new \RuntimeException("Table \"{$name}\" already exists.");
        }

        $columns = [];
        $primaryKey = $spec['primary key'] ?? [];

        foreach ($spec['fields'] as $fieldName => $fieldSpec) {
            $columns[] = $this->buildColumnDefinition($fieldName, $fieldSpec, $primaryKey);
        }

        // Add primary key constraint if not already handled by AUTOINCREMENT.
        if (!empty($primaryKey) && !$this->hasSerialPrimaryKey($spec['fields'], $primaryKey)) {
            $pkCols = implode(', ', array_map(fn(string $col) => $this->quoteIdentifier($col), $primaryKey));
            $columns[] = 'PRIMARY KEY (' . $pkCols . ')';
        }

        // Add unique keys as constraints.
        if (!empty($spec['unique keys'])) {
            foreach ($spec['unique keys'] as $keyName => $keyFields) {
                $keyCols = implode(', ', array_map(fn(string $col) => $this->quoteIdentifier($col), $keyFields));
                $columns[] = 'CONSTRAINT ' . $this->quoteIdentifier($keyName) . ' UNIQUE (' . $keyCols . ')';
            }
        }

        $sql = 'CREATE TABLE ' . $this->quoteIdentifier($name) . ' (' . implode(', ', $columns) . ')';
        $this->pdo->exec($sql);

        // Create indexes.
        if (!empty($spec['indexes'])) {
            foreach ($spec['indexes'] as $indexName => $indexFields) {
                $this->addIndex($name, $indexName, $indexFields);
            }
        }
    }

    public function dropTable(string $table): void
    {
        if (!$this->tableExists($table)) {
            throw new \RuntimeException("Table \"{$table}\" does not exist.");
        }

        $this->pdo->exec('DROP TABLE ' . $this->quoteIdentifier($table));
    }

    public function addField(string $table, string $field, array $spec): void
    {
        if (!$this->tableExists($table)) {
            throw new \RuntimeException("Table \"{$table}\" does not exist.");
        }

        if ($this->fieldExists($table, $field)) {
            throw new \RuntimeException("Field \"{$field}\" already exists in table \"{$table}\".");
        }

        $colDef = $this->buildColumnDefinition($field, $spec, []);
        $sql = 'ALTER TABLE ' . $this->quoteIdentifier($table) . ' ADD COLUMN ' . $colDef;
        $this->pdo->exec($sql);
    }

    public function dropField(string $table, string $field): void
    {
        if (!$this->tableExists($table)) {
            throw new \RuntimeException("Table \"{$table}\" does not exist.");
        }

        if (!$this->fieldExists($table, $field)) {
            throw new \RuntimeException("Field \"{$field}\" does not exist in table \"{$table}\".");
        }

        $sql = 'ALTER TABLE ' . $this->quoteIdentifier($table) . ' DROP COLUMN ' . $this->quoteIdentifier($field);
        $this->pdo->exec($sql);
    }

    public function addIndex(string $table, string $name, array $fields): void
    {
        $cols = implode(', ', array_map(fn(string $col) => $this->quoteIdentifier($col), $fields));
        $sql = 'CREATE INDEX ' . $this->quoteIdentifier($name) . ' ON ' . $this->quoteIdentifier($table) . ' (' . $cols . ')';
        $this->pdo->exec($sql);
    }

    public function dropIndex(string $table, string $name): void
    {
        $sql = 'DROP INDEX ' . $this->quoteIdentifier($name);
        $this->pdo->exec($sql);
    }

    public function addUniqueKey(string $table, string $name, array $fields): void
    {
        $cols = implode(', ', array_map(fn(string $col) => $this->quoteIdentifier($col), $fields));
        $sql = 'CREATE UNIQUE INDEX ' . $this->quoteIdentifier($name) . ' ON ' . $this->quoteIdentifier($table) . ' (' . $cols . ')';
        $this->pdo->exec($sql);
    }

    public function addPrimaryKey(string $table, array $fields): void
    {
        // SQLite does not support adding a primary key to an existing table.
        throw new \RuntimeException(
            'SQLite does not support adding a primary key to an existing table. '
            . 'Define the primary key when creating the table.'
        );
    }

    private function buildColumnDefinition(string $name, array $spec, array $primaryKey): string
    {
        $type = strtolower($spec['type'] ?? 'text');

        // Handle serial type (auto-increment).
        if ($type === 'serial') {
            return $this->quoteIdentifier($name) . ' INTEGER PRIMARY KEY AUTOINCREMENT';
        }

        $sqlType = $this->mapType($type);
        $parts = [$this->quoteIdentifier($name), $sqlType];

        if (!empty($spec['not null'])) {
            $parts[] = 'NOT NULL';
        }

        if (array_key_exists('default', $spec)) {
            $default = $spec['default'];
            if (is_string($default)) {
                $parts[] = 'DEFAULT ' . $this->pdo->quote($default);
            } elseif (is_int($default) || is_float($default)) {
                $parts[] = 'DEFAULT ' . $default;
            } elseif (is_bool($default)) {
                $parts[] = 'DEFAULT ' . ($default ? '1' : '0');
            } elseif ($default === null) {
                $parts[] = 'DEFAULT NULL';
            }
        }

        return implode(' ', $parts);
    }

    private function mapType(string $type): string
    {
        return match ($type) {
            'serial' => 'INTEGER',
            'varchar' => 'TEXT',
            'int', 'integer' => 'INTEGER',
            'text' => 'TEXT',
            'float', 'numeric', 'decimal' => 'REAL',
            'blob' => 'BLOB',
            default => 'TEXT',
        };
    }

    /**
     * Checks if the schema has a serial field that is the sole primary key.
     *
     * @param array<string, array<string, mixed>> $fields
     * @param string[] $primaryKey
     */
    private function hasSerialPrimaryKey(array $fields, array $primaryKey): bool
    {
        if (count($primaryKey) !== 1) {
            return false;
        }

        $pkField = $primaryKey[0];

        if (!isset($fields[$pkField])) {
            return false;
        }

        return strtolower($fields[$pkField]['type'] ?? '') === 'serial';
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
