<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Schema;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\EntityStorage\Backend\ReservedBackendIds;
use Waaseyaa\EntityStorage\Backend\SqlColumnSchemaBuilder;
use Waaseyaa\EntityStorage\CoordinatedEntitySchemaExecutor;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Allocates the `<table>__translation` sibling table for sql-column
 * translatable entity types (FR-026..FR-032, WP05).
 *
 * Storage layout:
 *   - Primary table `<table>` carries entity keys + non-translatable field columns.
 *   - Sibling table `<table>__translation` carries `(entity_id, langcode)` PK +
 *     translation-metadata columns + one column per translatable field.
 *   - FK `<table>__translation(entity_id)` REFERENCES `<table>(entity_id)` ON DELETE CASCADE.
 *
 * Partitioning rule (FR-026, FR-027):
 *   Walk `$entityType->getFieldDefinitions()`. Fields with
 *   `FieldDefinition::isTranslatable() === true` map to columns on the
 *   translation table. Non-translatable fields stay on the primary table
 *   (owned by {@see \Waaseyaa\EntityStorage\SqlSchemaHandler}); this handler
 *   does not touch them.
 *
 * Idempotency:
 *   `sync()` is safe to call repeatedly. When the table already exists it
 *   additively adds missing columns; it never drops or rewrites existing
 *   schema. Re-running the same sync against an already-current schema
 *   emits no DDL.
 *
 * Multi-cardinality fields (FR-032):
 *   Owned per-field tables (`<table>__<field>`) take their PK shape from the
 *   field's translatability:
 *     - Non-translatable multi-field: `(entity_id, delta)` PK,
 *       FK → `<table>(entity_id)`.
 *     - Translatable multi-field:     `(entity_id, langcode, delta)` PK,
 *       composite FK → `<table>__translation(entity_id, langcode)`.
 *   Materialised lazily by {@see ensureMultiCardinalityTable()} — the
 *   handler exposes the shape contract for upstream callers (storage backend
 *   writers, schema diff factories) that own the per-field write path.
 * @api
 */
final class TranslationSchemaHandler
{
    public const TRANSLATION_SUFFIX = '__translation';

    /**
     * Suffix for the two-axis per-revision blob table.
     *
     * Mirrors {@see RevisionTableBuilder::TRANSLATION_REVISION_SUFFIX}; duplicated
     * here so this handler does not have to import the builder for a single
     * constant lookup.
     */
    public const TRANSLATION_REVISION_SUFFIX = '__translation__revision';

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly DatabaseInterface $database,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Allocate (or additively extend) the translation sibling table for a
     * sql-column translatable entity type.
     *
     * No-op when the entity type is not translatable or its primary storage
     * backend is not `sql-column` — those layouts are owned elsewhere.
     */
    public function sync(EntityTypeInterface $entityType): void
    {
        if ($this->coordinateIfNeeded(fn() => $this->sync($entityType))) {
            return;
        }

        if (!$entityType->isTranslatable()) {
            return;
        }
        if (!$this->isSqlColumnBackend($entityType)) {
            return;
        }

        $tableName = $entityType->id();
        $translationTable = $this->translationTableName($tableName);

        $translatableFields = $this->partitionTranslatableFields($entityType);

        $schema = $this->database->schema();

        if (!$schema->tableExists($translationTable)) {
            $spec = $this->buildTranslationTableSpec(
                primaryTable: $tableName,
                translationTable: $translationTable,
                idKey: $entityType->getKeys()['id'] ?? 'id',
                translatableFields: $translatableFields,
            );
            $schema->createTable($translationTable, $spec);
            return;
        }

        // Additive idempotent sync: add any newly-registered translatable
        // field columns. Existing columns are left untouched (DBAL Schema
        // Comparator would emit no DDL when the desired and current shapes
        // already match).
        if ($this->database instanceof DBALDatabase) {
            $builder = new SqlColumnSchemaBuilder($this->database, $this->logger);
            foreach ($translatableFields as $field) {
                if ($field instanceof FieldDefinition) {
                    $builder->addFieldColumn($translationTable, $field);
                }
            }
        }
    }

    /**
     * Allocate the two-axis revision schema for entity types that are BOTH
     * revisionable AND translatable (M-004 / WP02).
     *
     * Emits the per-revision blob row table `<entity>__translation__revision`
     * (and its sibling `<entity>__revision`) by delegating to
     * {@see RevisionTableBuilder::buildTwoAxis()}. The shape is identical
     * across `sql-column` and `sql-blob` primary backends; the per-row
     * payload differs (one column per translatable field vs. a single
     * `_data` JSON blob).
     *
     * Single-axis types are no-ops here — the M-006 single-axis blob and
     * column paths are preserved unchanged (R-A regression gate, spec §12.3).
     *
     * Idempotent: skips tables that already exist.
     *
     * @param array<int|string, FieldDefinitionInterface>|null $fields Explicit
     *   field-definition list to drive partitioning. Defaults to the entity
     *   type's registered field definitions. Callers (e.g. {@see
     *   \Waaseyaa\EntityStorage\EntitySchemaSync}) MAY pass a subset that has
     *   already been filtered by backend (FR-006 boot-time guard).
     *
     * @throws \InvalidArgumentException When the entity type is not two-axis.
     * @throws \RuntimeException         When a translatable field is routed to
     *                                   a forbidden backend (FR-006, surfaced
     *                                   from `buildTwoAxis`).
     *
     * @api
     */
    public function syncTwoAxis(EntityTypeInterface $entityType, ?array $fields = null): void
    {
        if ($this->coordinateIfNeeded(fn() => $this->syncTwoAxis($entityType, $fields))) {
            return;
        }

        if (!$entityType->isTranslatable() || !$entityType->isRevisionable()) {
            // Single-axis paths are owned by {@see self::sync()} (translatable
            // sql-column primary table) and {@see RevisionTableBuilder::build()}
            // (revisionable single-axis). This method is two-axis only.
            return;
        }

        if (!$this->database instanceof DBALDatabase) {
            // RevisionTableBuilder requires DBALDatabase. Foundation-level
            // DatabaseInterface implementations not wired to DBAL skip silently
            // — they are not the target deployment substrate for two-axis types.
            return;
        }

        $backend = $entityType->getPrimaryStorageBackend();
        if ($backend !== ReservedBackendIds::SQL_COLUMN && $backend !== ReservedBackendIds::SQL_BLOB) {
            // Vector/remote primary backends cannot host two-axis revisions.
            return;
        }

        $effectiveFields = $fields !== null
            ? array_values(array_filter(
                $fields,
                static fn($f): bool => $f instanceof FieldDefinition,
            ))
            : array_values(array_filter(
                iterator_to_array($this->collectFieldDefinitions($entityType)),
                static fn($f): bool => $f instanceof FieldDefinition,
            ));

        $builder = new RevisionTableBuilder($this->database);
        $builder->buildTwoAxis($entityType, $backend, $effectiveFields);
    }

    /**
     * @return \Generator<int|string, FieldDefinitionInterface>
     */
    private function collectFieldDefinitions(EntityTypeInterface $entityType): \Generator
    {
        foreach ($entityType->getFieldDefinitions() as $name => $definition) {
            yield $name => $definition;
        }
    }

    /**
     * Materialise a multi-cardinality field table for the given field.
     *
     * @param string                   $entityTable Canonical entity table name.
     * @param string                   $idKey       Entity id key on the primary table.
     * @param FieldDefinitionInterface $field       The multi-cardinality field whose own table is required.
     *
     * Translatable fields receive `(entity_id, langcode, delta)` PK and a
     * composite FK to the translation sibling. Non-translatable fields keep
     * the legacy `(entity_id, delta)` shape (FR-032).
     */
    public function ensureMultiCardinalityTable(
        string $entityTable,
        string $idKey,
        FieldDefinitionInterface $field,
    ): void {
        if ($this->coordinateIfNeeded(
            fn() => $this->ensureMultiCardinalityTable($entityTable, $idKey, $field),
        )) {
            return;
        }

        $schema = $this->database->schema();
        $tableName = $this->multiCardinalityTableName($entityTable, $field->getName());

        if ($schema->tableExists($tableName)) {
            return;
        }

        $isTranslatable = $field->isTranslatable();

        $fields = [
            'entity_id' => ['type' => 'varchar', 'length' => 128, 'not null' => true],
            'delta'     => ['type' => 'int', 'not null' => true, 'default' => 0],
        ];

        if ($isTranslatable) {
            $fields = [
                'entity_id' => ['type' => 'varchar', 'length' => 128, 'not null' => true],
                'langcode'  => ['type' => 'varchar', 'length' => 12, 'not null' => true],
                'delta'     => ['type' => 'int', 'not null' => true, 'default' => 0],
            ];
        }

        // Per-field value column derives from the field definition.
        $fields['value'] = $this->deriveValueColumnSpec($field);

        $spec = [
            'fields'      => $fields,
            'primary key' => $isTranslatable
                ? ['entity_id', 'langcode', 'delta']
                : ['entity_id', 'delta'],
        ];

        // FK contract (FR-032):
        // - Translatable multi-field FK points at <table>__translation(entity_id, langcode).
        // - Non-translatable multi-field FK points at <table>(entity_id).
        if ($isTranslatable) {
            $spec['foreign keys'] = [
                $tableName . '_fk' => [
                    'table'      => $this->translationTableName($entityTable),
                    'columns'    => ['entity_id', 'langcode'],
                    'references' => ['entity_id', 'langcode'],
                    'options'    => ['onDelete' => 'CASCADE'],
                ],
            ];
        } else {
            $spec['foreign keys'] = [
                $tableName . '_fk' => [
                    'table'      => $entityTable,
                    'columns'    => ['entity_id'],
                    'references' => [$idKey],
                    'options'    => ['onDelete' => 'CASCADE'],
                ],
            ];
        }

        $schema->createTable($tableName, $spec);
    }

    /** Execute one top-level translation-schema transition under the coordinator. */
    private function coordinateIfNeeded(callable $transition): bool
    {
        $executor = new CoordinatedEntitySchemaExecutor($this->database);
        if ($executor->isActive()) {
            return false;
        }

        $executor->execute($transition);

        return true;
    }

    /**
     * Canonical translation table name: `<table>__translation`.
     */
    public function translationTableName(string $entityTable): string
    {
        return $entityTable . self::TRANSLATION_SUFFIX;
    }

    /**
     * Canonical multi-cardinality field-table name: `<table>__<fieldName>`.
     */
    public function multiCardinalityTableName(string $entityTable, string $fieldName): string
    {
        return $entityTable . '__' . $fieldName;
    }

    /**
     * Returns translatable FieldDefinitions only (T022 partition rule).
     *
     * @return array<string, FieldDefinitionInterface>
     */
    public function partitionTranslatableFields(EntityTypeInterface $entityType): array
    {
        $translatable = [];
        foreach ($entityType->getFieldDefinitions() as $name => $definition) {
            if ($definition->isTranslatable()) {
                $translatable[$name] = $definition;
            }
        }

        return $translatable;
    }

    /**
     * Whether the entity type's primary storage backend is `sql-column`.
     */
    private function isSqlColumnBackend(EntityTypeInterface $entityType): bool
    {
        return $entityType->getPrimaryStorageBackend() === ReservedBackendIds::SQL_COLUMN;
    }

    /**
     * Build the createTable spec for `<table>__translation`.
     *
     * @param array<string, FieldDefinitionInterface> $translatableFields
     * @return array<string, mixed>
     */
    private function buildTranslationTableSpec(
        string $primaryTable,
        string $translationTable,
        string $idKey,
        array $translatableFields,
    ): array {
        $fields = [
            'entity_id' => [
                'type'     => 'varchar',
                'length'   => 128,
                'not null' => true,
            ],
            'langcode' => [
                'type'     => 'varchar',
                'length'   => 12,
                'not null' => true,
            ],
            'translation_status' => [
                'type'     => 'varchar',
                'length'   => 32,
                'not null' => true,
                'default'  => 'draft',
            ],
            'translation_source' => [
                'type'     => 'varchar',
                'length'   => 12,
                'not null' => false,
            ],
            'translation_created' => [
                'type'     => 'varchar',
                'length'   => 32,
                'not null' => false,
            ],
            'translation_changed' => [
                'type'     => 'varchar',
                'length'   => 32,
                'not null' => false,
            ],
        ];

        // Append one column per translatable field.
        foreach ($translatableFields as $field) {
            $fields[$field->getName()] = $this->deriveValueColumnSpec($field);
        }

        return [
            'fields'      => $fields,
            'primary key' => ['entity_id', 'langcode'],
            'indexes'     => [
                $translationTable . '_langcode' => ['langcode'],
                $translationTable . '_status'   => ['translation_status'],
            ],
            'foreign keys' => [
                $translationTable . '_fk' => [
                    'table'      => $primaryTable,
                    'columns'    => ['entity_id'],
                    'references' => [$idKey],
                    'options'    => ['onDelete' => 'CASCADE'],
                ],
            ],
        ];
    }

    /**
     * Map a field definition to a column spec compatible with DBALSchema.
     *
     * Mirrors the type table used by {@see SqlColumnSchemaBuilder} so the
     * primary and translation tables agree on column shapes (risk note in
     * the WP05 spec: "Don't duplicate the type map").
     *
     * @return array<string, mixed>
     */
    private function deriveValueColumnSpec(FieldDefinitionInterface $field): array
    {
        $settings = $field->getSettings();

        $abstractType = ColumnSpecMap::abstractTypeFor($field->getType());
        $spec = ['type' => $abstractType];
        if ($abstractType === 'varchar') {
            $spec['length'] = (int) ($settings['length'] ?? 255);
        }

        $spec['not null'] = (bool) ($settings['not_null'] ?? false);
        if (\array_key_exists('default', $settings)) {
            $spec['default'] = $settings['default'];
        } elseif ($field->getDefaultValue() !== null) {
            $spec['default'] = $field->getDefaultValue();
        }

        return $spec;
    }
}
