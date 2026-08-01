<?php

declare(strict_types=1);

namespace Waaseyaa\Database\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Schema;
use Waaseyaa\Database\ForeignKeySchemaInterface;

/**
 * @api
 */
final class DBALSchema implements ForeignKeySchemaInterface
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

    /**
     * Whether $field exists as a column on $table.
     *
     * Doctrine keys `listTableColumns()` by the column's **quoted** name
     * whenever the identifier needs quoting — a reserved word such as `key`
     * arrives under the key `'"key"'` while the `Column` object's `getName()`
     * is the canonical `'key'`. A bare `isset($columns[$field])` therefore
     * reported every reserved-word column as absent (#2163), and callers acted
     * on the lie: `SqlColumnSchemaBuilder::addFieldColumn()` re-added a column
     * that already existed, and `SqlBlobBackend` routed the field into the
     * `_data` blob instead of its own column.
     *
     * The canonical name is the authority. Note this deliberately does not
     * inspect quote characters or consult a reserved-word list: which
     * identifiers need quoting is the platform's business, and `getName()`
     * already answers it portably for every driver.
     */
    public function fieldExists(string $table, string $field): bool
    {
        if (!$this->tableExists($table)) {
            return false;
        }

        $columns = $this->sm->listTableColumns($table);

        // Fast path for ordinary identifiers, which are keyed by their own
        // name. The name check keeps a *quoted* string such as `'"key"'` from
        // matching: that is not a column name, it is an identifier literal.
        if (isset($columns[$field]) && $columns[$field]->getName() === $field) {
            return true;
        }

        foreach ($columns as $column) {
            if ($column->getName() === $field) {
                return true;
            }
        }

        return false;
    }

    public function listTableNames(): array
    {
        // Doctrine's `AbstractSchemaManager::listTableNames()` is portable
        // across SQLite, MySQL, PostgreSQL, and other supported drivers.
        // No raw `sqlite_master`-style queries here — issue #1301 (deferred
        // mission #1257 WP09) replaced the SQLite-only path with this call.
        return $this->sm->listTableNames();
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

        if (!empty($spec['foreign keys'])) {
            foreach ($spec['foreign keys'] as $fkName => $fkDef) {
                $table->addForeignKeyConstraint(
                    $fkDef['table'],
                    $fkDef['columns'],
                    $fkDef['references'],
                    $fkDef['options'] ?? [],
                    is_string($fkName) ? $fkName : null,
                );
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
        if ($fields === []) {
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
        if ($fields === []) {
            throw new \InvalidArgumentException('Primary key fields must not be empty.');
        }
        if ($this->platform instanceof SQLitePlatform) {
            throw new \RuntimeException(
                'SQLite does not support adding a primary key to an existing table. '
                . 'Define the primary key when creating the table.',
            );
        }

        $currentSchema = $this->sm->introspectSchema();
        $newSchema = clone $currentSchema;
        $newSchema->getTable($table)->setPrimaryKey($fields);

        $diff = $this->sm->createComparator()
            ->compareSchemas($currentSchema, $newSchema);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql) {
            $this->connection->executeStatement($sql);
        }
    }

    public function foreignKeyExists(string $table, string $name): bool
    {
        foreach ($this->sm->listTableForeignKeys($table) as $foreignKey) {
            if ($foreignKey->getName() === $name) {
                return true;
            }
        }

        return false;
    }

    public function addForeignKey(
        string $table,
        string $name,
        array $columns,
        string $referencedTable,
        array $referencedColumns,
        array $options = [],
    ): void {
        if ($this->foreignKeyExists($table, $name)) {
            return;
        }

        $currentSchema = $this->sm->introspectSchema();
        $newSchema = clone $currentSchema;
        $newSchema->getTable($table)->addForeignKeyConstraint(
            $referencedTable,
            $columns,
            $referencedColumns,
            $options,
            $name,
        );

        $diff = $this->sm->createComparator()->compareSchemas($currentSchema, $newSchema);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql) {
            $this->connection->executeStatement($sql);
        }
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
