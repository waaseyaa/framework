<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Backend;

use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\EntityStorage\Schema\ColumnSpecMap;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * @api
 *
 * Schema builder for the sql-column backend (T026, T027).
 *
 * Generates the primary entity table with one SQL column per FieldDefinition,
 * using the §8.2 type mapping via {@see TypeMapping}. When a field is marked
 * {@see FieldDefinition::isIndexed()}, a B-tree index is emitted after the
 * table is created (T027).
 *
 * ## No _data column
 * The sql-column backend stores every registered field in its own column.
 * There is no `_data` TEXT blob — SqlSchemaHandler skips it when
 * primaryBackendId is `sql-column`.
 *
 * ## float_vector_<n> rejection
 * If any field definition has a `float_vector_<n>` type AND is not explicitly
 * routed to a different backend via `storedIn()`, this builder throws an
 * {@see \InvalidArgumentException} naming the offending field and suggesting
 * `storedIn('vector')`.
 *
 * ## Platform detection
 * The builder requires a {@see DBALDatabase} (not the abstract DatabaseInterface)
 * so it can retrieve the Doctrine DBAL platform for dialect dispatch.
 * Pass the same DBALDatabase used by the rest of the entity storage stack.
 *
 * ## DBALSchema type mapping
 * DBALSchema::createTable() maps Waaseyaa abstract types ('int', 'text', 'boolean',
 * 'float', 'serial', 'varchar') to Doctrine DBAL column types. We map every §8.2
 * FieldDefinition type to one of these abstract keys so the schema layer handles
 * platform-specific DDL emission (SQLite INTEGER vs Postgres BIGINT etc.).
 *
 * @internal Instantiate via the framework wire-up in SqlSchemaHandler; do not
 *           construct directly from application code.
 */
final class SqlColumnSchemaBuilder
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly DBALDatabase $database,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Materialise the primary entity table for a sql-column entity type.
     *
     * Called from SqlSchemaHandler::ensureTable() when primaryBackendId
     * is `sql-column`. Accepts the partial spec already built by
     * SqlSchemaHandler (entity keys) and adds one column per field definition.
     *
     * After the table is created, emits CREATE INDEX for every indexed field.
     *
     * @param EntityTypeInterface $entityType The entity type being materialised.
     * @param string              $tableName  Canonical table name.
     * @param FieldDefinition[]   $fields     Registered field definitions for this entity type.
     * @param array<string,mixed> $baseSpec   Partial createTable spec from SqlSchemaHandler
     *                                        (contains id/uuid/bundle/label/langcode columns).
     *
     * @throws \InvalidArgumentException When a float_vector_<n> field is not rerouted.
     */
    public function buildTable(
        EntityTypeInterface $entityType,
        string $tableName,
        array $fields,
        array $baseSpec,
    ): void {
        $spec = $baseSpec;
        $indexedFields = [];

        foreach ($fields as $field) {
            $fieldName = $field->getName();
            $fieldType = $field->getType();

            // Skip fields explicitly routed to a different backend.
            $backendId = $field->getBackendId();
            if ($backendId !== null && $backendId !== ReservedBackendIds::SQL_COLUMN) {
                continue;
            }

            // float_vector_<n> must be rerouted (§8.2).
            if (preg_match('/^float_vector_\d+$/', strtolower($fieldType))) {
                throw new \InvalidArgumentException(sprintf(
                    'Field "%s" on entity type "%s" has type "%s" which cannot be stored by the '
                    . 'sql-column backend. Route it to the vector backend: '
                    . 'FieldDefinition::create(\'%s\', \'%s\')->storedIn(\'vector\').',
                    $fieldName,
                    $entityType->id(),
                    $fieldType,
                    $fieldName,
                    $fieldType,
                ));
            }

            $settings = $field->getSettings();
            $spec['fields'][$fieldName] = $this->buildColumnSpec($fieldType, $settings);

            if ($field->isIndexed()) {
                $indexedFields[] = $fieldName;
            }
        }

        $schema = $this->database->schema();
        $schema->createTable($tableName, $spec);

        // Emit B-tree indexes for indexed fields (T027).
        foreach ($indexedFields as $indexedField) {
            $indexName = $tableName . '_' . $indexedField . '_idx';
            $this->database->query(
                'CREATE INDEX ' . $indexName . ' ON ' . $tableName . '(' . $indexedField . ')',
            );

            $this->logger->debug('SqlColumnSchemaBuilder: created index', [
                'index' => $indexName,
                'table' => $tableName,
                'field' => $indexedField,
            ]);
        }
    }

    /**
     * Add a single field column to an existing sql-column table (additive migration).
     *
     * Idempotent: no-op when the column already exists.
     *
     * @throws \InvalidArgumentException For float_vector_<n> fields.
     */
    public function addFieldColumn(string $tableName, FieldDefinition $field): void
    {
        $fieldName = $field->getName();
        $schema = $this->database->schema();

        if ($schema->fieldExists($tableName, $fieldName)) {
            return;
        }

        $columnSpec = $this->buildColumnSpec($field->getType(), $field->getSettings());
        $schema->addField($tableName, $fieldName, $columnSpec);

        if ($field->isIndexed()) {
            $indexName = $tableName . '_' . $fieldName . '_idx';
            $this->database->query(
                'CREATE INDEX IF NOT EXISTS ' . $indexName . ' ON ' . $tableName . '(' . $fieldName . ')',
            );
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build the Waaseyaa column spec array for a given field type.
     *
     * Maps §8.2 FieldDefinition types to the Waaseyaa abstract type keys that
     * DBALSchema::createTable() / mapFieldType() understands:
     *   'serial', 'int', 'varchar', 'text', 'blob', 'float', 'boolean'
     *
     * For precision/scale on decimal fields, the DBALSchema float type is used
     * (Doctrine maps it to DOUBLE PRECISION on Postgres and REAL on SQLite).
     * For true lossless decimal on SQLite we store as TEXT; on Postgres we rely
     * on Doctrine's 'decimal' type. We default to 'text' for the widest safety.
     *
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    private function buildColumnSpec(string $fieldType, array $settings): array
    {
        // Map §8.2 field type → Waaseyaa abstract type understood by DBALSchema.
        $abstractType = ColumnSpecMap::abstractTypeFor($fieldType);

        $spec = [
            'type'     => $abstractType,
            'not null' => (bool) ($settings['not_null'] ?? false),
        ];

        if (array_key_exists('default', $settings)) {
            $spec['default'] = $settings['default'];
        }

        // VARCHAR length.
        if ($abstractType === 'varchar') {
            $spec['length'] = isset($settings['length']) ? (int) $settings['length'] : 255;
        }

        return $spec;
    }
}
