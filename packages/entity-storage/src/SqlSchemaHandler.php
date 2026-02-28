<?php

declare(strict_types=1);

namespace Aurora\EntityStorage;

use Aurora\Database\DatabaseInterface;
use Aurora\Entity\EntityTypeInterface;

/**
 * Handles entity table schema creation and management.
 *
 * Generates SQL table schemas from entity type definitions and ensures
 * the required tables exist in the database. Supports translation tables
 * for translatable entity types.
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
     * Ensures the translation table exists for translatable entity types.
     *
     * The translation table stores per-language values for translatable
     * fields. Each row is keyed by (entity_id, langcode).
     *
     * @param array<string, array<string, mixed>> $translatableFieldSchemas
     *   Schemas for translatable field columns, keyed by column name.
     */
    public function ensureTranslationTable(array $translatableFieldSchemas = []): void
    {
        $schema = $this->database->schema();
        $translationTableName = $this->getTranslationTableName();

        if ($schema->tableExists($translationTableName)) {
            return;
        }

        $spec = $this->buildTranslationTableSpec($translatableFieldSchemas);
        $schema->createTable($translationTableName, $spec);
    }

    /**
     * Returns the table name for this entity type.
     */
    public function getTableName(): string
    {
        return $this->tableName;
    }

    /**
     * Returns the translation table name for this entity type.
     */
    public function getTranslationTableName(): string
    {
        return $this->tableName . '_translations';
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
     * Adds additional field columns to the translation table.
     *
     * @param array<string, array<string, mixed>> $fieldSchemas
     *   Field schemas keyed by column name.
     */
    public function addTranslationFieldColumns(array $fieldSchemas): void
    {
        $schema = $this->database->schema();
        $translationTableName = $this->getTranslationTableName();

        foreach ($fieldSchemas as $columnName => $columnSpec) {
            if (!$schema->fieldExists($translationTableName, $columnName)) {
                $schema->addField($translationTableName, $columnName, $columnSpec);
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

        // Data blob for extra/dynamic fields (JSON-encoded).
        $fields['_data'] = [
            'type' => 'text',
            'not null' => true,
            'default' => '{}',
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

    /**
     * Builds the translation table specification.
     *
     * Translation table schema:
     * - entity_id: references the base table's id
     * - langcode: language code for this translation
     * - translation_status: draft, published, needs_review
     * - translation_source: the source language this was translated from
     * - translation_created: when the translation was created
     * - translation_changed: when the translation was last modified
     * - _data: JSON blob for extra translatable fields
     * - Additional translatable field columns passed as parameter
     *
     * @param array<string, array<string, mixed>> $translatableFieldSchemas
     * @return array<string, mixed>
     */
    private function buildTranslationTableSpec(array $translatableFieldSchemas = []): array
    {
        $fields = [];

        // Foreign key to base table.
        $fields['entity_id'] = [
            'type' => 'varchar',
            'length' => 128,
            'not null' => true,
        ];

        // Language code.
        $fields['langcode'] = [
            'type' => 'varchar',
            'length' => 12,
            'not null' => true,
        ];

        // Translation metadata.
        $fields['translation_status'] = [
            'type' => 'varchar',
            'length' => 32,
            'not null' => true,
            'default' => 'draft',
        ];

        $fields['translation_source'] = [
            'type' => 'varchar',
            'length' => 12,
            'not null' => false,
        ];

        $fields['translation_created'] = [
            'type' => 'varchar',
            'length' => 32,
            'not null' => false,
        ];

        $fields['translation_changed'] = [
            'type' => 'varchar',
            'length' => 32,
            'not null' => false,
        ];

        // Add translatable field columns.
        foreach ($translatableFieldSchemas as $columnName => $columnSpec) {
            $fields[$columnName] = $columnSpec;
        }

        // Data blob for extra translatable dynamic fields.
        $fields['_data'] = [
            'type' => 'text',
            'not null' => true,
            'default' => '{}',
        ];

        $translationTableName = $this->getTranslationTableName();

        return [
            'fields' => $fields,
            'primary key' => ['entity_id', 'langcode'],
            'indexes' => [
                $translationTableName . '_langcode' => ['langcode'],
                $translationTableName . '_status' => ['translation_status'],
            ],
        ];
    }
}
