<?php

declare(strict_types=1);

namespace Waaseyaa\Database\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Schema;
use Waaseyaa\Database\SchemaInterface;

final class DBALSchema implements SchemaInterface
{
    private readonly AbstractSchemaManager $sm;

    private readonly AbstractPlatform $platform;

    public function __construct(
        private readonly Connection $connection,
    ) {
        $this->sm = $connection->createSchemaManager();
        $this->platform = $connection->getDatabasePlatform();
    }

    public function tableExists(string $table): bool
    {
        return $this->sm->tablesExist([$table]);
    }

    public function fieldExists(string $table, string $field): bool
    {
        if (!$this->tableExists($table)) {
            return false;
        }

        $columns = $this->sm->listTableColumns($table);

        return isset($columns[$field]);
    }

    public function createTable(string $name, array $spec): void
    {
        if ($this->tableExists($name)) {
            throw new \RuntimeException("Table \"{$name}\" already exists.");
        }

        $schema = new Schema();
        $table = $schema->createTable($name);

        $primaryKey = $spec['primary key'] ?? [];

        foreach ($spec['fields'] as $fieldName => $fieldSpec) {
            $type = $this->mapFieldType($fieldSpec['type']);
            $options = $this->mapFieldOptions($fieldSpec, $primaryKey, $fieldName);
            $table->addColumn($fieldName, $type, $options);
        }

        if (!empty($primaryKey)) {
            $table->setPrimaryKey($primaryKey);
        }

        if (!empty($spec['unique keys'])) {
            foreach ($spec['unique keys'] as $keyName => $keyFields) {
                $table->addUniqueIndex($keyFields, $keyName);
            }
        }

        if (!empty($spec['indexes'])) {
            foreach ($spec['indexes'] as $indexName => $indexFields) {
                $table->addIndex($indexFields, $indexName);
            }
        }

        $queries = $schema->toSql($this->platform);
        foreach ($queries as $sql) {
            $this->connection->executeStatement($sql);
        }
    }

    public function dropTable(string $table): void
    {
        if (!$this->tableExists($table)) {
            throw new \RuntimeException("Table \"{$table}\" does not exist.");
        }

        $this->sm->dropTable($table);
    }

    public function addField(string $table, string $field, array $spec): void
    {
        if (!$this->tableExists($table)) {
            throw new \RuntimeException("Table \"{$table}\" does not exist.");
        }

        if ($this->fieldExists($table, $field)) {
            throw new \RuntimeException("Field \"{$field}\" already exists in table \"{$table}\".");
        }

        $currentSchema = $this->sm->introspectSchema();
        $newSchema = clone $currentSchema;

        $tableObj = $newSchema->getTable($table);
        $type = $this->mapFieldType($spec['type']);
        $options = $this->mapFieldOptions($spec, [], $field);
        $tableObj->addColumn($field, $type, $options);

        $diff = $this->sm->createComparator()
            ->compareSchemas($currentSchema, $newSchema);
        $queries = $this->platform->getAlterSchemaSQL($diff);
        foreach ($queries as $sql) {
            $this->connection->executeStatement($sql);
        }
    }

    public function dropField(string $table, string $field): void
    {
        if (!$this->tableExists($table)) {
            throw new \RuntimeException("Table \"{$table}\" does not exist.");
        }

        if (!$this->fieldExists($table, $field)) {
            throw new \RuntimeException("Field \"{$field}\" does not exist in table \"{$table}\".");
        }

        $currentSchema = $this->sm->introspectSchema();
        $newSchema = clone $currentSchema;

        $tableObj = $newSchema->getTable($table);
        $tableObj->dropColumn($field);

        $diff = $this->sm->createComparator()
            ->compareSchemas($currentSchema, $newSchema);
        $queries = $this->platform->getAlterSchemaSQL($diff);
        foreach ($queries as $sql) {
            $this->connection->executeStatement($sql);
        }
    }

    public function addIndex(string $table, string $name, array $fields): void
    {
        if (empty($fields)) {
            throw new \InvalidArgumentException('Index fields must not be empty.');
        }

        $currentSchema = $this->sm->introspectSchema();
        $newSchema = clone $currentSchema;

        $tableObj = $newSchema->getTable($table);
        /** @var non-empty-array<int, string> $fields */
        $tableObj->addIndex($fields, $name);

        $diff = $this->sm->createComparator()
            ->compareSchemas($currentSchema, $newSchema);
        $queries = $this->platform->getAlterSchemaSQL($diff);
        foreach ($queries as $sql) {
            $this->connection->executeStatement($sql);
        }
    }

    public function dropIndex(string $table, string $name): void
    {
        $currentSchema = $this->sm->introspectSchema();
        $newSchema = clone $currentSchema;

        $tableObj = $newSchema->getTable($table);
        $tableObj->dropIndex($name);

        $diff = $this->sm->createComparator()
            ->compareSchemas($currentSchema, $newSchema);
        $queries = $this->platform->getAlterSchemaSQL($diff);
        foreach ($queries as $sql) {
            $this->connection->executeStatement($sql);
        }
    }

    public function addUniqueKey(string $table, string $name, array $fields): void
    {
        if (empty($fields)) {
            throw new \InvalidArgumentException('Unique key fields must not be empty.');
        }

        $currentSchema = $this->sm->introspectSchema();
        $newSchema = clone $currentSchema;

        $tableObj = $newSchema->getTable($table);
        /** @var non-empty-array<int, string> $fields */
        $tableObj->addUniqueIndex($fields, $name);

        $diff = $this->sm->createComparator()
            ->compareSchemas($currentSchema, $newSchema);
        $queries = $this->platform->getAlterSchemaSQL($diff);
        foreach ($queries as $sql) {
            $this->connection->executeStatement($sql);
        }
    }

    public function addPrimaryKey(string $table, array $fields): void
    {
        // SQLite does not support adding a primary key to an existing table.
        throw new \RuntimeException(
            'SQLite does not support adding a primary key to an existing table. '
            . 'Define the primary key when creating the table.',
        );
    }

    private function mapFieldType(string $waaseyaaType): string
    {
        return match (strtolower($waaseyaaType)) {
            'serial' => 'integer',
            'int', 'integer' => 'integer',
            'varchar', 'string' => 'text',
            'text' => 'text',
            'blob' => 'blob',
            'float', 'real', 'numeric', 'decimal' => 'float',
            'boolean', 'bool' => 'boolean',
            default => 'text',
        };
    }

    /**
     * @param array<string, mixed> $spec
     * @param string[] $primaryKey
     * @return array<string, mixed>
     */
    private function mapFieldOptions(array $spec, array $primaryKey, string $fieldName): array
    {
        $options = [];

        if (strtolower($spec['type'] ?? '') === 'serial') {
            $options['autoincrement'] = true;
        }

        if (isset($spec['not null'])) {
            $options['notnull'] = (bool) $spec['not null'];
        } else {
            // Default to nullable to match SQLite behavior.
            $options['notnull'] = false;
        }

        if (array_key_exists('default', $spec)) {
            $options['default'] = $spec['default'];
        }

        if (isset($spec['length'])) {
            $options['length'] = $spec['length'];
        }

        return $options;
    }
}
