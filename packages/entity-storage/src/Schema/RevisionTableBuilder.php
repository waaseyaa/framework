<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Schema;

use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\EntityStorage\Backend\ReservedBackendIds;
use Waaseyaa\EntityStorage\Exception\StorageMigrationException;
use Waaseyaa\Field\FieldDefinition;

/**
 * Emits the `<entity>__revision` table schema for a revisionable entity type.
 *
 * ## When to use
 *
 * Call {@see self::build()} from `SqlSchemaHandler::ensureTable()` (or equivalent)
 * when `EntityTypeInterface::isRevisionable()` returns `true` and the revision
 * table does not yet exist.
 *
 * ## Table layout — sql-column primary
 *
 * ```
 * <entity>__revision(
 *     vid       INTEGER PRIMARY KEY,   -- autoincrement on SQLite, SERIAL on Postgres
 *     <id_col>  <pk_type>,             -- FK to primary table (soft — no ON DELETE)
 *     revision_created_at  TEXT,       -- ISO-8601 (SQLite) / TIMESTAMPTZ (Postgres)
 *     revision_author      INTEGER,    -- nullable UID, soft FK only
 *     revision_log         TEXT,       -- nullable log message
 *     -- one column per FieldDefinition (same spec as primary table)
 * )
 * ```
 *
 * ## Table layout — sql-blob primary
 *
 * ```
 * <entity>__revision(
 *     vid       INTEGER PRIMARY KEY,
 *     <id_col>  <pk_type>,
 *     revision_created_at  TEXT,
 *     revision_author      INTEGER,
 *     revision_log         TEXT,
 *     _data     TEXT,                  -- JSON blob (mirrors sql-blob primary)
 * )
 * ```
 *
 * ## Soft FK for revision_author
 *
 * `revision_author` stores the UID of the account that created the revision.
 * No `ON DELETE` cascade is emitted — revision history MUST survive user deletion.
 * This is intentional per spec §3.5 / contracts/revisionable-entity.md §3.2.
 *
 * ## Platform note
 *
 * For `vid PRIMARY KEY`, SQLite uses `INTEGER PRIMARY KEY` (implicit ROWID /
 * autoincrement). Postgres uses `SERIAL` / `BIGSERIAL`. We emit `serial` as the
 * Waaseyaa abstract type so `DBALSchema` handles the dialect difference.
 *
 * ## Two-axis (revisionable + translatable) emission
 *
 * When the entity type is BOTH `revisionable: true` AND `translatable: true`,
 * call {@see self::buildTwoAxis()} (M-004 / WP01). This emits TWO tables:
 *
 *   - `<entity>__revision`               — non-translatable field columns only;
 *                                          one row per default-langcode revision
 *                                          (FR-004: stored once on default-langcode).
 *   - `<entity>__translation__revision`  — translatable field columns; one row
 *                                          per `(entity_id, langcode, vid)` triple;
 *                                          surrogate `vid PRIMARY KEY` for
 *                                          ergonomic `loadRevision($vid)`, plus a
 *                                          composite `UNIQUE (entity_id, langcode, vid)`
 *                                          index to express the logical PK
 *                                          (R-01 in contracts/composite-pk.md).
 *
 * Single-axis revisionable-only types continue to use {@see self::build()}
 * with byte-for-byte unchanged output (R-A risk mitigation, spec §12.3).
 *
 * ## Forbidden backend guard (FR-006)
 *
 * When the entity type is two-axis, any registered field whose
 * `FieldDefinition::isTranslatable()` is `true` and whose backend resolves
 * to `vector` (or any non-sql backend not in
 * {@see ReservedBackendIds::SQL_COLUMN}, {@see ReservedBackendIds::SQL_BLOB})
 * raises {@see StorageMigrationException::unsupportedTwoAxisField()} at boot
 * (typed `\RuntimeException` subclass with stable `errorCode`
 * `'unsupported_two_axis_field'`). The factory message retains the literal
 * `unsupportedTwoAxisField` marker so contract tests asserting on the token
 * keep passing across the WP01 → WP04 swap.
 *
 * @api
 */
final class RevisionTableBuilder
{
    public const TRANSLATION_REVISION_SUFFIX = '__translation__revision';

    public function __construct(
        private readonly DBALDatabase $database,
    ) {}

    /**
     * Materialise the `<entity>__revision` table.
     *
     * Idempotent: does nothing when the table already exists.
     *
     * @param EntityTypeInterface        $entityType       The revisionable entity type.
     * @param string                     $primaryBackendId The primary backend id (`'sql-blob'`
     *                                                     or `'sql-column'`).
     * @param FieldDefinition[]          $fields           Registered field definitions for this type.
     *
     * @throws \InvalidArgumentException When `$entityType` is not revisionable.
     * @throws \InvalidArgumentException When `$entityType` has no `'id'` key.
     *
     * @api
     */
    public function build(
        EntityTypeInterface $entityType,
        string $primaryBackendId,
        array $fields = [],
    ): void {
        if (!$entityType->isRevisionable()) {
            throw new \InvalidArgumentException(\sprintf(
                'RevisionTableBuilder::build() requires a revisionable entity type; '
                . '"%s" has revisionable=false.',
                $entityType->id(),
            ));
        }

        $keys = $entityType->getKeys();
        $idColumn = $keys['id'] ?? '';
        if ($idColumn === '') {
            throw new \InvalidArgumentException(\sprintf(
                'Entity type "%s" has no "id" key; cannot emit revision table.',
                $entityType->id(),
            ));
        }

        $revisionTable = $entityType->id() . '__revision';

        if ($this->database->schema()->tableExists($revisionTable)) {
            return;
        }

        $spec = $this->buildSpec(
            primaryBackendId: $primaryBackendId,
            idColumn: $idColumn,
            fields: $fields,
        );

        $this->database->schema()->createTable($revisionTable, $spec);
    }

    /**
     * Materialise the two-axis revision schema for entity types that are
     * BOTH revisionable AND translatable (M-004 / WP01).
     *
     * Emits two sibling tables:
     *   - `<entity>__revision`              non-translatable field columns
     *                                        (FR-004, stored once per default-langcode revision).
     *   - `<entity>__translation__revision` translatable field columns
     *                                        keyed `(entity_id, langcode, vid)`.
     *
     * Idempotent: skips tables that already exist.
     *
     * Honours FR-006: any translatable field routed to a backend other than
     * `sql-column` / `sql-blob` raises
     * {@see StorageMigrationException::unsupportedTwoAxisField()} (typed
     * `\RuntimeException` subclass; stable error code
     * `'unsupported_two_axis_field'`).
     *
     * @param EntityTypeInterface                              $entityType
     * @param string                                           $primaryBackendId       `'sql-column'` or `'sql-blob'`.
     * @param array<int|string, FieldDefinition>      $fields                  Registered field definitions.
     *
     * @throws \InvalidArgumentException When `$entityType` is not revisionable or not translatable.
     * @throws \InvalidArgumentException When `$entityType` has no `'id'` key.
     * @throws StorageMigrationException When any translatable field is routed to a forbidden backend (FR-006).
     *
     * @api
     */
    public function buildTwoAxis(
        EntityTypeInterface $entityType,
        string $primaryBackendId,
        array $fields = [],
    ): void {
        if (!$entityType->isRevisionable()) {
            throw new \InvalidArgumentException(\sprintf(
                'RevisionTableBuilder::buildTwoAxis() requires a revisionable entity type; '
                . '"%s" has revisionable=false.',
                $entityType->id(),
            ));
        }
        if (!$entityType->isTranslatable()) {
            throw new \InvalidArgumentException(\sprintf(
                'RevisionTableBuilder::buildTwoAxis() requires a translatable entity type; '
                . '"%s" has translatable=false. Use build() for single-axis revisionable types.',
                $entityType->id(),
            ));
        }

        $keys = $entityType->getKeys();
        $idColumn = $keys['id'] ?? '';
        if ($idColumn === '') {
            throw new \InvalidArgumentException(\sprintf(
                'Entity type "%s" has no "id" key; cannot emit two-axis revision tables.',
                $entityType->id(),
            ));
        }

        // FR-006 — boot-time guard: translatable fields on forbidden backends.
        $this->assertNoTranslatableFieldsOnUnsupportedBackend($entityType, $fields);

        // T002 — partition fields: non-translatable go on <entity>__revision,
        // translatable go on <entity>__translation__revision.
        [$nonTranslatable, $translatable] = $this->partitionByTranslatability($fields);

        $schema = $this->database->schema();

        $revisionTable = $entityType->id() . '__revision';
        if (!$schema->tableExists($revisionTable)) {
            // FR-004: non-translatable fields live here, once per default-langcode revision.
            // No `langcode` column on this table — the (entity, langcode) → vid mapping
            // is owned by `<entity>__translation` (M-006 substrate).
            $spec = $this->buildSpec(
                primaryBackendId: $primaryBackendId,
                idColumn: $idColumn,
                fields: $nonTranslatable,
            );
            $schema->createTable($revisionTable, $spec);
        }

        $translationRevisionTable = $entityType->id() . self::TRANSLATION_REVISION_SUFFIX;
        if (!$schema->tableExists($translationRevisionTable)) {
            // FR-001 / FR-002: composite identity (entity_id, langcode, vid).
            // Surrogate `vid PRIMARY KEY` keeps loadRevision($vid) cheap and gives
            // monotonic ordering for interleaved listRevisions() (R-01).
            $spec = $this->buildTranslationRevisionSpec(
                primaryBackendId: $primaryBackendId,
                idColumn: $idColumn,
                translatableFields: $translatable,
                translationRevisionTable: $translationRevisionTable,
            );
            $schema->createTable($translationRevisionTable, $spec);
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build the createTable spec array for the revision table.
     *
     * @param FieldDefinition[] $fields
     * @return array<string, mixed>
     */
    private function buildSpec(
        string $primaryBackendId,
        string $idColumn,
        array $fields,
    ): array {
        // vid is always the PRIMARY KEY of the revision table.
        // We use 'serial' so DBALSchema maps to SERIAL/BIGSERIAL (Postgres) or
        // INTEGER PRIMARY KEY AUTOINCREMENT (SQLite).
        $spec = [
            'fields' => [
                'vid' => [
                    'type'     => 'serial',
                    'not null' => true,
                ],
                $idColumn => [
                    'type'     => 'int',
                    'not null' => true,
                ],
            ],
            'primary key' => ['vid'],
        ];

        // T039: revision metadata columns on every revision table.
        $spec['fields']['revision_created_at'] = [
            'type'     => 'text',
            'not null' => false,
        ];
        $spec['fields']['revision_author'] = [
            'type'     => 'int',
            'not null' => false,
            // Intentionally NO foreign key ON DELETE — soft FK only so revision
            // history survives user deletion (spec §3.5, contract §3.2).
        ];
        $spec['fields']['revision_log'] = [
            'type'     => 'text',
            'not null' => false,
        ];

        // Backend-specific field columns.
        if ($primaryBackendId === 'sql-column') {
            $this->appendColumnFields($spec, $fields);
        } else {
            // sql-blob path: store all non-key data as a JSON blob, mirroring the
            // primary table's _data column.
            $spec['fields']['_data'] = [
                'type'     => 'text',
                'not null' => false,
            ];
        }

        return $spec;
    }

    /**
     * Append one column per field definition (sql-column path).
     *
     * Uses the same type-mapping as `SqlColumnSchemaBuilder::buildColumnSpec()`
     * so that revision-table columns have the same shape as primary-table columns.
     *
     * @param array<string, mixed>  $spec   Modified in-place.
     * @param FieldDefinition[]     $fields
     */
    private function appendColumnFields(array &$spec, array $fields): void
    {
        foreach ($fields as $field) {
            $fieldName = $field->getName();
            // Skip fields explicitly routed to a different (non-sql-column) backend.
            $backendId = $field->getBackendId();
            if ($backendId !== null && $backendId !== '' && $backendId !== 'sql-column') {
                continue;
            }

            $spec['fields'][$fieldName] = $this->columnSpecForType(
                $field->getType(),
                $field->getSettings(),
            );
        }
    }

    /**
     * Map a FieldDefinition type + settings to a DBALSchema column spec array.
     *
     * Mirrors the mapping in `SqlColumnSchemaBuilder::buildColumnSpec()` so that
     * revision-table columns have the same shape as primary-table columns.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function columnSpecForType(string $fieldType, array $settings): array
    {
        $abstractType = ColumnSpecMap::abstractTypeFor($fieldType);

        $spec = [
            'type'     => $abstractType,
            'not null' => (bool) ($settings['not_null'] ?? false),
        ];

        if (array_key_exists('default', $settings)) {
            $spec['default'] = $settings['default'];
        }

        if ($abstractType === 'varchar') {
            $spec['length'] = isset($settings['length']) ? (int) $settings['length'] : 255;
        }

        return $spec;
    }

    /**
     * Build the createTable spec for `<entity>__translation__revision`.
     *
     * Surrogate `vid INTEGER PRIMARY KEY` + composite `UNIQUE (entity_id, langcode, vid)`
     * index to express the logical primary key without sacrificing query
     * ergonomics. See contracts/composite-pk.md §3 (R-01).
     *
     * @param array<int|string, FieldDefinition> $translatableFields
     * @return array<string, mixed>
     */
    private function buildTranslationRevisionSpec(
        string $primaryBackendId,
        string $idColumn,
        array $translatableFields,
        string $translationRevisionTable,
    ): array {
        $spec = [
            'fields' => [
                'vid' => [
                    'type'     => 'serial',
                    'not null' => true,
                ],
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
            ],
            'primary key' => ['vid'],
        ];

        // Revision metadata mirrors the single-axis revision table.
        $spec['fields']['revision_created_at'] = [
            'type'     => 'text',
            'not null' => false,
        ];
        $spec['fields']['revision_author'] = [
            'type'     => 'int',
            'not null' => false,
        ];
        $spec['fields']['revision_log'] = [
            'type'     => 'text',
            'not null' => false,
        ];

        // Backend-specific translatable-field columns.
        if ($primaryBackendId === ReservedBackendIds::SQL_COLUMN) {
            foreach ($translatableFields as $field) {
                $backendId = $field->getBackendId();
                if ($backendId !== null && $backendId !== '' && $backendId !== ReservedBackendIds::SQL_COLUMN) {
                    // Field is routed to a different backend (e.g. attachment).
                    // The forbidden-backend guard runs earlier and short-circuits
                    // vector / remote translatable fields; anything reaching here
                    // belongs on another sql-* table and is not materialised here.
                    continue;
                }
                $spec['fields'][$field->getName()] = $this->columnSpecForType(
                    $field->getType(),
                    $field->getSettings(),
                );
            }
        } else {
            // sql-blob: translatable values live in a single JSON blob.
            $spec['fields']['_data'] = [
                'type'     => 'text',
                'not null' => false,
            ];
        }

        // FR-001 — logical primary key expressed as a composite UNIQUE index.
        $spec['indexes'] = [
            // Per-language ordering index (used by listRevisions($langcode)).
            $translationRevisionTable . '_lang_vid_idx' => ['entity_id', 'langcode', 'vid'],
        ];
        $spec['unique keys'] = [
            $translationRevisionTable . '_logical_pk' => ['entity_id', 'langcode', 'vid'],
        ];

        // Note on FKs (FR-008 / §7 of contracts/composite-pk.md):
        //   - `entity_id` references `<entity>(<id>)`: enforced by callers via
        //     transactional writes (RevisionableStorageDriver in WP04+).
        //   - `(entity_id, langcode)` references `<entity>__translation(entity_id, langcode)`:
        //     same — RevisionableStorageDriver enforces. We emit the surrogate-PK
        //     shape only; composite FKs across sibling tables are added when
        //     the multi-cardinality table builder (WP05) requires them.

        // Idempotency hint: the soft `<id>` column on the non-translatable
        // revision table mirrors single-axis output (no `entity_id` rename) —
        // see build() — but the translation-revision table uses the
        // contract-mandated `entity_id` name (matches `<entity>__translation`).
        // Suppress the unused-param warning: idColumn is reserved for future
        // ALTER paths that may add a soft FK column to this table.
        unset($idColumn);

        return $spec;
    }

    /**
     * Partition fields into [non-translatable, translatable].
     *
     * @param array<int|string, FieldDefinition> $fields
     * @return array{0: list<FieldDefinition>, 1: list<FieldDefinition>}
     */
    private function partitionByTranslatability(array $fields): array
    {
        $nonTranslatable = [];
        $translatable = [];
        foreach ($fields as $field) {
            if ($field->isTranslatable()) {
                $translatable[] = $field;
            } else {
                $nonTranslatable[] = $field;
            }
        }
        return [$nonTranslatable, $translatable];
    }

    /**
     * FR-006 — reject translatable fields routed to backends that cannot
     * carry per-language column / blob storage (e.g. `vector`, `remote`).
     *
     * Raises the typed {@see StorageMigrationException::unsupportedTwoAxisField()}
     * factory (introduced in WP04). The message preserves the literal
     * `unsupportedTwoAxisField` marker plus the field name and backend id so
     * existing contract assertions continue to pass.
     *
     * @param array<int|string, FieldDefinition> $fields
     *
     * @throws StorageMigrationException When any translatable field routes to a forbidden backend.
     */
    private function assertNoTranslatableFieldsOnUnsupportedBackend(
        EntityTypeInterface $entityType,
        array $fields,
    ): void {
        unset($entityType); // entity-type id is implied by call site; factory carries field + backend.

        $allowedBackends = [
            ReservedBackendIds::SQL_COLUMN,
            ReservedBackendIds::SQL_BLOB,
            // null / '' resolves to the entity type's primary backend, which is
            // assumed sql-* by the caller (buildTwoAxis is only invoked for sql-*
            // primary backends).
        ];

        foreach ($fields as $field) {
            if (!$field->isTranslatable()) {
                continue;
            }
            $backendId = $field->getBackendId();
            if ($backendId === null || $backendId === '') {
                continue;
            }
            if (in_array($backendId, $allowedBackends, true)) {
                continue;
            }
            throw StorageMigrationException::unsupportedTwoAxisField($field->getName(), $backendId);
        }
    }
}
