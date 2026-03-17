<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

final class SchemaBuilder
{
    private const TYPE_MAP = [
        'string' => Types::STRING,
        'text' => Types::TEXT,
        'integer' => Types::INTEGER,
        'boolean' => Types::BOOLEAN,
        'float' => Types::FLOAT,
        'json' => Types::JSON,
        'datetime_immutable' => Types::DATETIME_IMMUTABLE,
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly string $tablePrefix = '',
    ) {}

    public function create(string $table, \Closure $callback): void
    {
        $builder = new TableBuilder();
        $callback($builder);

        $prefixedTable = $this->tablePrefix . $table;
        $schema = new Schema();
        $dbalTable = $schema->createTable($prefixedTable);

        $primaryColumns = [];
        foreach ($builder->getColumns() as $col) {
            $type = self::TYPE_MAP[$col->type] ?? Types::STRING;
            $options = [];
            if ($col->length !== null) {
                $options['length'] = $col->length;
            }
            if ($col->isNullable()) {
                $options['notnull'] = false;
            }
            if ($col->hasDefaultValue()) {
                $options['default'] = $col->getDefaultValue();
            }
            $dbalTable->addColumn($col->name, $type, $options);

            if ($col->isUnique()) {
                $dbalTable->addUniqueIndex([$col->name]);
            }
        }

        $pk = $builder->getPrimaryKey();
        if ($pk !== null) {
            $dbalTable->setPrimaryKey($pk);
        } elseif ($this->hasColumnNamed($builder, 'id')) {
            $dbalTable->setPrimaryKey(['id']);
        }

        foreach ($builder->getUniqueIndexes() as $idx) {
            $dbalTable->addUniqueIndex($idx['columns']);
        }

        foreach ($builder->getIndexes() as $idx) {
            $dbalTable->addIndex($idx['columns'], $idx['name'] ?? null);
        }

        $platform = $this->connection->getDatabasePlatform();
        foreach ($schema->toSql($platform) as $sql) {
            $this->connection->executeStatement($sql);
        }
    }

    public function drop(string $table): void
    {
        $prefixed = $this->tablePrefix . $table;
        $this->connection->executeStatement("DROP TABLE {$prefixed}");
    }

    public function dropIfExists(string $table): void
    {
        $prefixed = $this->tablePrefix . $table;
        $this->connection->executeStatement("DROP TABLE IF EXISTS {$prefixed}");
    }

    public function hasTable(string $table): bool
    {
        $prefixed = $this->tablePrefix . $table;
        return $this->connection->createSchemaManager()->tablesExist([$prefixed]);
    }

    public function hasColumn(string $table, string $column): bool
    {
        $prefixed = $this->tablePrefix . $table;
        $columns = $this->connection->createSchemaManager()->listTableColumns($prefixed);
        foreach ($columns as $col) {
            if ($col->getName() === $column) {
                return true;
            }
        }
        return false;
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    private function hasColumnNamed(TableBuilder $builder, string $name): bool
    {
        foreach ($builder->getColumns() as $col) {
            if ($col->name === $name) {
                return true;
            }
        }
        return false;
    }
}
