<?php

declare(strict_types=1);

namespace Aurora\EntityStorage;

use Aurora\Database\DatabaseInterface;
use Aurora\Entity\EntityTypeInterface;

/**
 * Handles entity table schema creation and management.
 *
 * Generates SQL table schemas from entity type definitions and ensures
 * the required tables exist in the database.
 */
final class SqlSchemaHandler
{
    private readonly string $tableName;

    public function __construct(
        private readonly EntityTypeInterface $entityType,
        private readonly DatabaseInterface $database,
    ) {
        $this->tableName = $this->entityType->id();
    }

    /**
     * Ensures the entity table exists, creating it if necessary.
     */
    public function ensureTable(): void
    {
        $schema = $this->database->schema();

        if ($schema->tableExists($this->tableName)) {
            return;
        }

        $spec = $this->buildTableSpec();
        $schema->createTable($this->tableName, $spec);
    }

    /**
     * Returns the table name for this entity type.
     */
    public function getTableName(): string
    {
        return $this->tableName;
    }

    /**
     * Adds additional field columns to an existing entity table.
     *
     * @param array<string, array<string, mixed>> $fieldSchemas
     *   Field schemas keyed by column name, each with 'type', 'not null', 'default', etc.
     */
    public function addFieldColumns(array $fieldSchemas): void
    {
        $schema = $this->database->schema();

        foreach ($fieldSchemas as $columnName => $columnSpec) {
            if (!$schema->fieldExists($this->tableName, $columnName)) {
                $schema->addField($this->tableName, $columnName, $columnSpec);
            }
        }
    }

    /**
     * Builds the table specification array for createTable().
     *
     * @return array<string, mixed>
     */
    private function buildTableSpec(): array
    {
        $keys = $this->entityType->getKeys();
        $fields = [];

        // ID column (serial / auto-increment).
        $idKey = $keys['id'] ?? 'id';
        $fields[$idKey] = [
            'type' => 'serial',
            'not null' => true,
        ];

        // UUID column.
        $uuidKey = $keys['uuid'] ?? 'uuid';
        $fields[$uuidKey] = [
            'type' => 'varchar',
            'length' => 128,
            'not null' => true,
            'default' => '',
        ];

        // Bundle column.
        $bundleKey = $keys['bundle'] ?? 'bundle';
        $fields[$bundleKey] = [
            'type' => 'varchar',
            'length' => 128,
            'not null' => true,
            'default' => '',
        ];

        // Label column.
        $labelKey = $keys['label'] ?? 'label';
        $fields[$labelKey] = [
            'type' => 'varchar',
            'length' => 255,
            'not null' => true,
            'default' => '',
        ];

        // Langcode column.
        $langcodeKey = $keys['langcode'] ?? 'langcode';
        $fields[$langcodeKey] = [
            'type' => 'varchar',
            'length' => 12,
            'not null' => true,
            'default' => 'en',
        ];

        return [
            'fields' => $fields,
            'primary key' => [$idKey],
            'unique keys' => [
                $this->tableName . '_uuid' => [$uuidKey],
            ],
            'indexes' => [
                $this->tableName . '_bundle' => [$bundleKey],
            ],
        ];
    }
}
