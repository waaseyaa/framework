<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\ForeignKeySchemaInterface;
use Waaseyaa\Entity\EntityTypeForeignKeyDefinitionInterface;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\EntityStorage\Backend\ReservedBackendIds;
use Waaseyaa\EntityStorage\Backend\SqlColumnSchemaBuilder;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionInterface;
use Waaseyaa\Field\FieldStorage;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Handles entity table schema creation and management.
 *
 * Generates SQL table schemas from entity type definitions and ensures
 * the required tables exist in the database. Supports translation tables
 * for translatable entity types, and — for multi-bundle entity types whose
 * bundles carry registered fields — per-bundle subtables named
 * `{base_table}__{bundle}`. See docs/specs/bundle-scoped-storage.md.
 * @api
 */
final class SqlSchemaHandler
{
    private readonly string $tableName;

    private readonly LoggerInterface $logger;

    /**
     * @param \Closure|null $bundleEnumerator fn(EntityTypeInterface): iterable<string>
     *   Optional override for bundle discovery — typically backed by the
     *   bundle-entity-type config storage, used when the caller needs to
     *   enumerate bundles beyond those currently in the registry (e.g. a
     *   declared-but-empty bundle that still wants a subtable, or a pre-flight
     *   schema rebuild from config). When null, the registry's
     *   bundleNamesFor() is used as the default source — which matches
     *   SqlEntityStorage's save-time partitioning so both paths agree on
     *   which bundles are "known". When $fieldRegistry is also null the
     *   bundle loop is skipped and behavior matches the pre-bundle-scoped
     *   status quo.
     * @param string $primaryBackendId The primary storage backend id for this entity type.
     *   `sql-blob` (default) emits the `_data` TEXT column for dynamic fields.
     *   `sql-column` (WP05) defers to SqlColumnSchemaBuilder and does NOT emit `_data`.
     *   Any other value is treated as `sql-blob` during the WP03–WP04 migration window.
     * @param FieldDefinition[] $entityLevelFields Entity-level field definitions to add as columns
     *   when primaryBackendId is `sql-column`. Ignored for sql-blob.
     */
    public function __construct(
        private readonly EntityTypeInterface $entityType,
        private readonly DatabaseInterface $database,
        private readonly ?FieldDefinitionRegistryInterface $fieldRegistry = null,
        private readonly ?\Closure $bundleEnumerator = null,
        ?LoggerInterface $logger = null,
        private readonly string $primaryBackendId = ReservedBackendIds::SQL_BLOB,
        private readonly array $entityLevelFields = [],
    ) {
        $this->tableName = $this->entityType->id();
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Ensures the entity table and any registered non-empty bundle subtables exist.
     *
     * Base-table creation is existing behavior. For multi-bundle entity types,
     * this additionally enumerates registered bundles and materializes the
     * `{base_table}__{bundle}` subtable for any bundle that has at least one
     * registered FieldDefinition. Bundles with zero registered fields have no
     * subtable — both install-time default and a legitimate steady state.
     *
     * Idempotent: re-runs never drop and never fail on existing tables; they
     * additively create columns for any field registered since the last run.
     */
    public function ensureTable(): void
    {
        $schema = $this->database->schema();

        if (!$schema->tableExists($this->tableName)) {
            if ($this->primaryBackendId === ReservedBackendIds::SQL_COLUMN
                && $this->database instanceof DBALDatabase
                && $this->entityLevelFields !== []
            ) {
                // sql-column path (WP05): SqlColumnSchemaBuilder adds per-field columns
                // to the base spec and emits indexes for indexed() fields.
                $builder = new SqlColumnSchemaBuilder($this->database, $this->logger);
                $builder->buildTable(
                    $this->entityType,
                    $this->tableName,
                    $this->entityLevelFields,
                    $this->buildTableSpec(),
                );
            } else {
                $schema->createTable($this->tableName, $this->buildTableSpec());
            }

            // sql-blob translatable types: PK is (entity_id, langcode); UUID
            // uniqueness must be enforced only on the default-langcode row, so we
            // materialize a partial UNIQUE INDEX after table creation. (FR-020, FR-025)
            if ($this->isSqlBlobTranslatable()) {
                $this->ensureSqlBlobTranslatablePartialUuidIndex();
            }
        } elseif ($this->primaryBackendId === ReservedBackendIds::SQL_COLUMN
            && $this->database instanceof DBALDatabase
            && $this->entityLevelFields !== []
        ) {
            // Table already exists: additively add any new field columns.
            $builder = new SqlColumnSchemaBuilder($this->database, $this->logger);
            foreach ($this->entityLevelFields as $field) {
                $builder->addFieldColumn($this->tableName, $field);
            }
        }

        $this->ensureDeclaredForeignKeys();

        if (!$this->shouldProcessBundles()) {
            return;
        }

        foreach ($this->registeredBundlesFor($this->entityType) as $bundle) {
            $bundleFields = $this->fieldRegistry->bundleFieldsFor($this->entityType->id(), $bundle);
            if ($bundleFields === []) {
                continue;
            }
            $this->ensureBundleSubtable($bundle, $bundleFields);
        }
    }

    /** Add declared restrictive relationships once both participating tables exist. */
    public function ensureDeclaredForeignKeys(): void
    {
        if (!$this->entityType instanceof EntityTypeForeignKeyDefinitionInterface) {
            return;
        }
        $schema = $this->database->schema();
        if (!$schema instanceof ForeignKeySchemaInterface) {
            return;
        }
        if (!$schema->tableExists($this->tableName)) {
            return;
        }

        foreach ($this->entityType->getStorageForeignKeys() as $definition) {
            if (!$schema->tableExists($definition['table'])) {
                continue;
            }
            $schema->addForeignKey(
                $this->tableName,
                $definition['name'],
                $definition['columns'],
                $definition['table'],
                $definition['references'],
                $definition['options'] ?? [],
            );
        }
    }

    /**
     * Creates (or additively updates) the subtable for a given bundle.
     *
     * Called directly by migrations for the empty→non-empty bundle transition
     * and indirectly by ensureTable() for install-time creation. Idempotent.
     *
     * @param array<string, FieldDefinitionInterface> $bundleFields Field definitions
     *   for this bundle keyed by field name; must satisfy the registry's
     *   self-description invariants (targetEntityTypeId + targetBundle match).
     *
     * @throws \InvalidArgumentException If $bundle contains the subtable
     *   separator '__'; the separator is load-bearing in the name format and
     *   cannot appear in a bundle identifier.
     */
    public function ensureBundleSubtable(string $bundle, array $bundleFields): void
    {
        $subtableName = $this->bundleSubtableName($bundle);
        $schema = $this->database->schema();

        if (!$schema->tableExists($subtableName)) {
            $schema->createTable($subtableName, $this->buildBundleSubtableSpec($subtableName, $bundleFields));
            return;
        }

        foreach ($bundleFields as $field) {
            if ($field->getStored() === FieldStorage::Data) {
                continue;
            }
            $columnName = $field->getName();
            if (!$schema->fieldExists($subtableName, $columnName)) {
                $schema->addField($subtableName, $columnName, $this->deriveColumnSpec($field));
            }
        }
    }

    /**
     * Returns the subtable name for the given bundle: `{base_table}__{bundle}`.
     *
     * Thin wrapper around {@see self::resolveSubtableName()} that supplies the
     * handler's own base table and entity type id.
     *
     * @throws \InvalidArgumentException If $bundle contains '__'.
     */
    public function bundleSubtableName(string $bundle): string
    {
        return self::resolveSubtableName($this->tableName, $bundle, $this->entityType->id());
    }

    /**
     * Canonical formatter for bundle subtable names: `{baseTable}__{bundle}`.
     *
     * Single source of truth shared by SqlSchemaHandler, SqlEntityStorage, and
     * SqlEntityQuery (mission #1257 WP03, K1). Rejects bundle identifiers that
     * contain the reserved `__` separator. The structural guard at
     * EntityTypeManager::addBundleFields() prevents bad input upstream; this
     * helper enforces the same invariant at the formatting boundary.
     *
     * @throws \InvalidArgumentException When `$bundle` contains `__`.
     */
    public static function resolveSubtableName(string $baseTable, string $bundle, ?string $entityTypeId = null): string
    {
        if (str_contains($bundle, '__')) {
            throw new \InvalidArgumentException(\sprintf(
                'Bundle identifier "%s" contains the reserved separator "__"; '
                . 'it cannot be used in bundle-scoped subtable names%s.',
                $bundle,
                $entityTypeId !== null ? ' for entity type "' . $entityTypeId . '"' : '',
            ));
        }

        return $baseTable . '__' . $bundle;
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
     * Ensures the revision table exists for revisionable entity types.
     *
     * The revision table stores snapshots of all field values for each revision.
     * Primary key is composite (entity_id, revision_id).
     *
     * When the table already exists, revision-metadata columns added since it
     * was created are synced additively (`fieldExists` → targeted raw
     * `ALTER TABLE … ADD COLUMN`, see {@see ensureRevisionAuthorColumn()}
     * re #1653) — currently `revision_author` (mission
     * revision-audit-provenance-01KTWY5V FR-003). Idempotent; no other column
     * is touched and no row is rewritten (C-001). Runs at kernel boot /
     * db:init, never per save.
     */
    public function ensureRevisionTable(): void
    {
        $schema = $this->database->schema();
        $revisionTableName = $this->getRevisionTableName();

        if ($schema->tableExists($revisionTableName)) {
            $this->ensureRevisionAuthorColumn($revisionTableName);
            return;
        }

        $spec = $this->buildRevisionTableSpec();
        $schema->createTable($revisionTableName, $spec);
    }

    /**
     * Additive sync arm for the `revision_author` column on a pre-existing
     * revision (or translation-revision) table (FR-003, contract
     * revision-author.md clauses 12–14).
     *
     * Nullable int, NO default, NO FK constraint (soft FK — revision history
     * survives user deletion; definition adopted verbatim from the dormant
     * RevisionTableBuilder dialect, research D7). Pre-existing rows read back
     * SQL NULL with zero migration. Without this arm the column would only
     * exist on fresh installs and foldData() would silently absorb the author
     * into the `_data` blob.
     */
    private function ensureRevisionAuthorColumn(string $tableName): void
    {
        $schema = $this->database->schema();

        if ($schema->fieldExists($tableName, 'revision_author')) {
            return;
        }

        // Targeted ALTER, not DBALSchema::addField() (#1653, alpha.205
        // regression): addField() runs whole-schema introspection
        // (AbstractSchemaManager::introspectSchema()), which throws on
        // databases containing FTS5 virtual tables — their shadow tables
        // have typeless columns that DBAL 4.4's SQLite column parser cannot
        // handle. fieldExists() above introspects only the named table, so
        // the guard is safe; the ADD COLUMN itself needs no introspection.
        // Same pattern as the audit package's actor_uid arm
        // (AuditEventSchemaHandler): metadata-only DDL, nullable, no
        // default, no FK — portable across SQLite/MySQL/PostgreSQL.
        $quote = $this->database instanceof DBALDatabase
            ? fn(string $id): string => $this->database->quoteIdentifier($id)
            : static fn(string $id): string => '"' . str_replace('"', '""', $id) . '"';

        $this->database->query(\sprintf(
            'ALTER TABLE %s ADD COLUMN %s INTEGER',
            $quote($tableName),
            $quote('revision_author'),
        ));
    }

    /**
     * Returns the revision table name for this entity type.
     */
    public function getRevisionTableName(): string
    {
        return $this->tableName . '_revision';
    }

    /**
     * Translation-revision table name for two-axis entity types:
     * `<entity>__translation__revision`.
     */
    public function getTranslationRevisionTableName(): string
    {
        return $this->tableName . '__translation__revision';
    }

    /**
     * Ensure the per-language revision table exists for entity types that are
     * BOTH revisionable AND translatable (the optional translation axis).
     *
     * Additive and gated: single-axis (revisionable-only) types never get this
     * table, so their schema is byte-for-byte unchanged. Each row is one
     * revision of one language; `revision_id` is monotonic per
     * `(entity_id, langcode)` so languages sequence independently. Field values
     * ride the `_data` JSON blob, mirroring the base/`_revision` table; the
     * driver folds non-key fields into it.
     */
    public function ensureTranslationRevisionTable(): void
    {
        if (!$this->entityType->isRevisionable() || !$this->entityType->isTranslatable()) {
            return;
        }

        $schema = $this->database->schema();
        $tableName = $this->getTranslationRevisionTableName();
        if ($schema->tableExists($tableName)) {
            // Same additive author-column sync as ensureRevisionTable() —
            // the translation axis gets identical treatment (DIR-005).
            $this->ensureRevisionAuthorColumn($tableName);
            return;
        }

        $schema->createTable($tableName, $this->buildTranslationRevisionTableSpec());
    }

    /**
     * Seed revision 1 for all existing rows in the base table.
     *
     * Used when enabling revisions on an entity type with existing data.
     * Must run after ensureRevisionTable().
     */
    public function seedRevisions(): void
    {
        $db = $this->database;
        $keys = $this->entityType->getKeys();
        $idKey = $keys['id'] ?? 'id';
        $revisionKey = $keys['revision'] ?? 'revision_id';
        $revisionTable = $this->getRevisionTableName();

        $result = $db->select($this->tableName)
            ->fields($this->tableName)
            ->execute();

        foreach ($result as $row) {
            $row = (array) $row;
            $entityId = (string) $row[$idKey];

            // Skip if revision already exists.
            $existing = $db->query(
                "SELECT 1 FROM {$revisionTable} WHERE entity_id = ? AND revision_id = 1",
                [$entityId],
            );
            $found = false;
            foreach ($existing as $_) {
                $found = true;
                break;
            }
            if ($found) {
                continue;
            }

            $revRow = ['entity_id' => $entityId, 'revision_id' => 1];
            $revRow['revision_created'] = date('Y-m-d H:i:s');
            $revRow['revision_log'] = 'Seeded from existing data';
            foreach ($row as $col => $val) {
                // Skip the base-table-only pointer columns: the current-revision
                // pointer and the published-revision pointer have no counterpart
                // in the revision snapshot table.
                if ($col === $idKey || $col === $revisionKey || $col === 'published_revision_id') {
                    continue;
                }
                $revRow[$col] = $val;
            }

            $db->insert($revisionTable)
                ->fields(array_keys($revRow))
                ->values($revRow)
                ->execute();

            $db->update($this->tableName)
                ->fields([$revisionKey => 1])
                ->condition($idKey, $entityId)
                ->execute();
        }
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

        // ID column: varchar for config entities, serial for content entities.
        // Exception (FR-020): sql-blob translatable types widen the PK to
        // (entity_id, langcode); 'serial' implies autoincrement which on SQLite
        // also adds a UNIQUE constraint on (id) alone — that would break the
        // multi-row-per-id model. For those types we emit a plain `int` and
        // assign ids in SqlEntityStorage's translatable write path.
        $idKey = $keys['id'] ?? 'id';
        if (isset($keys['uuid'])) {
            $fields[$idKey] = [
                'type' => $this->isSqlBlobTranslatable() ? 'int' : 'serial',
                'not null' => true,
            ];
        } else {
            $fields[$idKey] = [
                'type' => 'varchar',
                'length' => 255,
                'not null' => true,
            ];
        }

        // UUID column (content entities only).
        if (isset($keys['uuid'])) {
            $fields[$keys['uuid']] = [
                'type' => 'varchar',
                'length' => 128,
                'not null' => true,
                'default' => '',
            ];
        }

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

        // Default-langcode column for translatable entity types (FR-020, FR-025, FR-027).
        // sql-blob: lives on every row so a single SELECT identifies the canonical row.
        // sql-column: lives on the single primary row and pins the default language
        // (the translation sibling table carries per-langcode overlays).
        if ($this->entityType->isTranslatable()) {
            $fields['default_langcode'] = [
                'type' => 'varchar',
                'length' => 12,
                'not null' => true,
                'default' => 'en',
            ];
        }

        // Revision pointer column (revisionable entity types only).
        if ($this->entityType->isRevisionable()) {
            $revisionKey = $keys['revision'] ?? 'revision_id';
            $fields[$revisionKey] = [
                'type' => 'int',
                'not null' => false,
                'default' => null,
            ];

            // Published-revision pointer. Records which revision the public /
            // "published" view renders, kept SEPARATE from the current/latest
            // revision pointer above so the live view can differ from an
            // in-progress draft (draft-then-publish; publishing an older
            // revision is rollback). Nullable, default NULL: an entity with
            // nothing published yet — and every pre-existing revisionable row —
            // is unaffected (the column reads NULL). Moved only via
            // EntityRepository::setPublishedRevision(); see
            // EntityRepository::loadPublishedRevision().
            $fields['published_revision_id'] = [
                'type' => 'int',
                'not null' => false,
                'default' => null,
            ];
        }

        // Tenant scoping is a storage boundary, not an application-field
        // convention. Keep the discriminator as an indexed physical column on
        // both sql-blob and sql-column backends so scoped reads and writes can
        // never depend on JSON extraction or undeclared dynamic data.
        $tenancy = $this->entityType->getTenancy();
        if ($tenancy !== null && $tenancy['scope'] === 'community') {
            $fields['community_id'] = [
                'type' => 'varchar',
                'length' => 128,
                'not null' => true,
                'default' => '',
            ];
        }

        // Data blob for extra/dynamic fields (JSON-encoded).
        // sql-column backend (WP05) manages its own columns and does not use _data.
        if ($this->primaryBackendId !== ReservedBackendIds::SQL_COLUMN) {
            $fields['_data'] = [
                'type' => 'text',
                'not null' => true,
                'default' => '{}',
            ];
        } else {
            // sql-column backend: per-field columns are added by SqlColumnSchemaBuilder
            // in ensureTable() after this spec is used to create the base table.
            // No _data column is emitted here (wired in ensureTable() below).
        }

        // FR-020: sql-blob translatable types widen the primary key to
        // (entity_id, langcode) so each langcode gets its own row.
        $primaryKey = $this->isSqlBlobTranslatable()
            ? [$idKey, $langcodeKey]
            : [$idKey];

        $spec = [
            'fields' => $fields,
            'primary key' => $primaryKey,
            'indexes' => [
                $this->tableName . '_bundle' => [$bundleKey],
            ],
        ];
        if (isset($fields['community_id'])) {
            $spec['indexes'][$this->tableName . '_community'] = ['community_id'];
        }

        // UUID uniqueness rules:
        //   - Non-translatable content entities: a plain UNIQUE KEY on (uuid).
        //   - Translatable sql-blob types: every translation row shares the
        //     same uuid as the default-langcode row, so a global UNIQUE on
        //     (uuid) would block valid inserts. Uniqueness is instead enforced
        //     by a partial UNIQUE INDEX `<table>_uuid_default` materialized in
        //     ensureTable() — see ensureSqlBlobTranslatablePartialUuidIndex().
        if (isset($keys['uuid']) && !$this->isSqlBlobTranslatable()) {
            $spec['unique keys'] = [
                $this->tableName . '_uuid' => [$keys['uuid']],
            ];
        }

        return $spec;
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

    /**
     * Builds the revision table specification.
     *
     * Mirrors the base table field columns plus revision metadata.
     * PK is composite (entity_id, revision_id).
     *
     * @return array<string, mixed>
     */
    private function buildRevisionTableSpec(): array
    {
        $keys = $this->entityType->getKeys();
        $fields = [];

        // Entity ID foreign key.
        $fields['entity_id'] = [
            'type' => 'varchar',
            'length' => 128,
            'not null' => true,
        ];

        // Revision ID — monotonic integer per entity.
        $fields['revision_id'] = [
            'type' => 'int',
            'not null' => true,
        ];

        // Revision metadata.
        $fields['revision_created'] = [
            'type' => 'varchar',
            'length' => 32,
            'not null' => true,
        ];

        $fields['revision_log'] = [
            'type' => 'text',
            'not null' => false,
        ];

        // Acting account that created the revision (FR-001/FR-003). Nullable,
        // NO default (absence is SQL NULL, never 0), NO FK constraint — soft
        // FK so history survives user deletion (D7 dialect convergence).
        $fields['revision_author'] = [
            'type' => 'int',
            'not null' => false,
        ];

        // Mirror base table field columns.
        $labelKey = $keys['label'] ?? 'label';
        $fields[$labelKey] = [
            'type' => 'varchar',
            'length' => 255,
            'not null' => true,
            'default' => '',
        ];

        $bundleKey = $keys['bundle'] ?? 'bundle';
        $fields[$bundleKey] = [
            'type' => 'varchar',
            'length' => 128,
            'not null' => true,
            'default' => '',
        ];

        $langcodeKey = $keys['langcode'] ?? 'langcode';
        $fields[$langcodeKey] = [
            'type' => 'varchar',
            'length' => 12,
            'not null' => true,
            'default' => 'en',
        ];

        if (isset($keys['uuid'])) {
            $fields[$keys['uuid']] = [
                'type' => 'varchar',
                'length' => 128,
                'not null' => true,
                'default' => '',
            ];
        }

        // Data blob for extra fields.
        $fields['_data'] = [
            'type' => 'text',
            'not null' => true,
            'default' => '{}',
        ];

        return [
            'fields' => $fields,
            'primary key' => ['entity_id', 'revision_id'],
            'indexes' => [],
        ];
    }

    /**
     * Builds the createTable spec for `<entity>__translation__revision`.
     *
     * One row per `(entity_id, langcode, revision_id)`; `revision_id` is
     * monotonic per `(entity_id, langcode)` so each language has its own
     * revision sequence (independent per-language history). Non-key field values
     * ride the `_data` blob, mirroring the base/`_revision` tables — the driver
     * folds them in. The composite key is the logical primary key; the index
     * serves per-language tip/list lookups.
     *
     * @return array<string, mixed>
     */
    private function buildTranslationRevisionTableSpec(): array
    {
        $tableName = $this->getTranslationRevisionTableName();

        $fields = [
            'entity_id' => ['type' => 'varchar', 'length' => 128, 'not null' => true],
            'langcode' => ['type' => 'varchar', 'length' => 12, 'not null' => true],
            'revision_id' => ['type' => 'int', 'not null' => true],
            'revision_created' => ['type' => 'varchar', 'length' => 32, 'not null' => true],
            'revision_log' => ['type' => 'text', 'not null' => false],
            // Acting account (FR-001/FR-003): nullable int, no default, soft FK
            // — identical definition to the single-axis revision table.
            'revision_author' => ['type' => 'int', 'not null' => false],
            '_data' => ['type' => 'text', 'not null' => true, 'default' => '{}'],
        ];

        return [
            'fields' => $fields,
            'primary key' => ['entity_id', 'langcode', 'revision_id'],
            'indexes' => [
                $tableName . '_lang_rev' => ['entity_id', 'langcode', 'revision_id'],
            ],
        ];
    }

    /**
     * Whether the bundle-subtable loop should run for this entity type.
     */
    private function shouldProcessBundles(): bool
    {
        return $this->fieldRegistry !== null
            && $this->entityType->getBundleEntityType() !== null;
    }

    /**
     * Enumerates bundles to consider for subtable materialization.
     *
     * Prefers the explicit $bundleEnumerator when supplied — it can include
     * bundles declared via the bundle-entity-type config that have no
     * registered fields yet (an empty-subtable branch in ensureTable()
     * handles those cleanly). Otherwise falls back to the registry's
     * bundleNamesFor(), which is the same source SqlEntityStorage uses for
     * save-time partitioning, so schema-side and write-side agree on which
     * bundles are "known".
     *
     * @return iterable<string>
     */
    private function registeredBundlesFor(EntityTypeInterface $type): iterable
    {
        if ($this->bundleEnumerator !== null) {
            return ($this->bundleEnumerator)($type);
        }

        \assert($this->fieldRegistry !== null);

        return $this->fieldRegistry->bundleNamesFor($type->id());
    }

    /**
     * Builds the createTable spec for a bundle subtable.
     *
     * PK shares the base table's id key; FK references the base with
     * ON DELETE CASCADE so deleting an entity drops its extension row.
     *
     * @param array<string, FieldDefinitionInterface> $bundleFields
     * @return array<string, mixed>
     */
    private function buildBundleSubtableSpec(string $subtableName, array $bundleFields): array
    {
        $keys = $this->entityType->getKeys();
        $idKey = $keys['id'] ?? 'id';

        $fields = [];

        $fields[$idKey] = [
            'type' => isset($keys['uuid']) ? 'int' : 'varchar',
            'length' => isset($keys['uuid']) ? null : 255,
            'not null' => true,
        ];
        if ($fields[$idKey]['length'] === null) {
            unset($fields[$idKey]['length']);
        }

        foreach ($bundleFields as $field) {
            if ($field->getStored() === FieldStorage::Data) {
                continue;
            }
            $fields[$field->getName()] = $this->deriveColumnSpec($field);
        }

        return [
            'fields' => $fields,
            'primary key' => [$idKey],
            'foreign keys' => [
                $subtableName . '_fk' => [
                    'table' => $this->tableName,
                    'columns' => [$idKey],
                    'references' => [$idKey],
                    'options' => ['onDelete' => 'CASCADE'],
                ],
            ],
        ];
    }

    /**
     * Maps a FieldDefinition's type to a Waaseyaa column spec.
     *
     * Unknown types fall back to `text` and emit a {@see LoggerInterface::warning()}
     * so operators see typos or missing mappings at schema-build time. Settings keys
     * `length`, `not_null`, and `default` are honored when present; otherwise they
     * default to nullable with no default.
     *
     * @return array<string, mixed>
     */
    private function deriveColumnSpec(FieldDefinitionInterface $field): array
    {
        return self::buildColumnSpecArray($field, $this->logger, $this->tableName);
    }

    /**
     * Public diff-spec entry point used by mission #529's
     * {@see Schema\EntityDiffFactory}.
     *
     * Returns the canonical foundation {@see \Waaseyaa\Foundation\Schema\Diff\ColumnSpec}
     * value type for a given field — same mapping table as
     * {@see deriveColumnSpec()}, but in the algebraic shape the
     * SchemaDiff layer consumes. This is the single source of truth
     * for field-type → column derivation; per WP07 risk note, no
     * duplicate mapping lives in the factory.
     */
    public static function deriveDiffColumnSpec(FieldDefinitionInterface $field): \Waaseyaa\Foundation\Schema\Diff\ColumnSpec
    {
        $array = self::buildColumnSpecArray($field, null, null);

        return new \Waaseyaa\Foundation\Schema\Diff\ColumnSpec(
            type: (string) $array['type'],
            nullable: ! (bool) ($array['not null'] ?? false),
            default: $array['default'] ?? null,
            length: isset($array['length']) ? (int) $array['length'] : null,
        );
    }

    /**
     * Whether this handler is materializing a translatable sql-blob schema.
     *
     * Internal helper centralising the (backend == sql-blob && isTranslatable())
     * check used by buildTableSpec() and ensureTable() for FR-020..FR-025.
     */
    private function isSqlBlobTranslatable(): bool
    {
        return $this->primaryBackendId !== ReservedBackendIds::SQL_COLUMN
            && $this->entityType->isTranslatable();
    }

    /**
     * Materialise the partial UNIQUE INDEX guarding UUID uniqueness across
     * default-langcode rows of translatable sql-blob types (FR-020, FR-025).
     *
     *   CREATE UNIQUE INDEX <table>_uuid_default
     *       ON <table>(uuid) WHERE langcode = default_langcode
     *
     * Portability:
     *   - SQLite (>=3.8) and PostgreSQL (>=9.0) support partial indexes natively.
     *   - MySQL/MariaDB do not. For those platforms we degrade to a regular
     *     index on (uuid) and emit an operator warning; uniqueness across
     *     default-langcode rows must be enforced at the application layer.
     */
    private function ensureSqlBlobTranslatablePartialUuidIndex(): void
    {
        $keys = $this->entityType->getKeys();
        if (!isset($keys['uuid'])) {
            return;
        }

        $uuidColumn = $keys['uuid'];
        $langcodeColumn = $keys['langcode'] ?? 'langcode';
        $indexName = $this->tableName . '_uuid_default';

        $quote = $this->database instanceof DBALDatabase
            ? fn(string $id): string => $this->database->quoteIdentifier($id)
            : static fn(string $id): string => '"' . str_replace('"', '""', $id) . '"';

        $platform = $this->detectDatabasePlatform();

        if ($platform === 'mysql' || $platform === 'mariadb') {
            $this->logger->warning(\sprintf(
                'SqlSchemaHandler: MySQL/MariaDB does not support partial unique '
                . 'indexes; degrading translatable UUID guard on "%s" to a non-unique '
                . 'index. Default-langcode UUID uniqueness must be enforced by the '
                . 'application layer for translatable sql-blob entity types.',
                $this->tableName,
            ));
            $sql = \sprintf(
                'CREATE INDEX IF NOT EXISTS %s ON %s (%s)',
                $quote($indexName),
                $quote($this->tableName),
                $quote($uuidColumn),
            );
            $this->database->query($sql);
            return;
        }

        $sql = \sprintf(
            'CREATE UNIQUE INDEX IF NOT EXISTS %s ON %s (%s) WHERE %s = %s',
            $quote($indexName),
            $quote($this->tableName),
            $quote($uuidColumn),
            $quote($langcodeColumn),
            $quote('default_langcode'),
        );
        $this->database->query($sql);
    }

    /**
     * Best-effort platform discovery for partial-index emission.
     *
     * Returns 'sqlite', 'postgresql', 'mysql', 'mariadb', or 'unknown'.
     * When the database is not a DBALDatabase (e.g. a test stub), returns
     * 'sqlite' which yields the standards-compliant partial index syntax.
     */
    private function detectDatabasePlatform(): string
    {
        $db = $this->database;
        if (!$db instanceof DBALDatabase) {
            return 'sqlite';
        }
        try {
            $platform = $db->getConnection()->getDatabasePlatform();
            $platformClass = strtolower($platform::class);
        } catch (\Throwable) {
            return 'unknown';
        }
        if (str_contains($platformClass, 'sqlite')) {
            return 'sqlite';
        }
        if (str_contains($platformClass, 'postgres')) {
            return 'postgresql';
        }
        if (str_contains($platformClass, 'mariadb')) {
            return 'mariadb';
        }
        if (str_contains($platformClass, 'mysql')) {
            return 'mysql';
        }
        return 'unknown';
    }

    /**
     * Shared private static — single owner of the field-type → spec
     * mapping table. Both {@see deriveColumnSpec()} (per-instance,
     * with logger context) and {@see deriveDiffColumnSpec()} (static,
     * for the SchemaDiff factory) call this.
     *
     * @return array<string, mixed>
     */
    private static function buildColumnSpecArray(
        FieldDefinitionInterface $field,
        ?LoggerInterface $logger,
        ?string $tableName,
    ): array {
        $settings = $field->getSettings();
        $typeKey = strtolower($field->getType());

        $spec = match ($typeKey) {
            'string' => ['type' => 'varchar', 'length' => (int) ($settings['length'] ?? 255)],
            'text' => ['type' => 'text'],
            'text_long' => ['type' => 'text'],
            // A JSON-encoded structured value (e.g. a decoded block tree) is a
            // TEXT column; the field type carries the JSON Schema separately.
            'json' => ['type' => 'text'],
            'uri' => ['type' => 'varchar', 'length' => (int) ($settings['length'] ?? 2048)],
            'email' => ['type' => 'varchar', 'length' => (int) ($settings['length'] ?? 255)],
            // ISO-8601 datetime / Y-m-d date are stored as fixed-width strings,
            // matching the DateTimeItem / DateItem field-type schemas.
            'datetime' => ['type' => 'varchar', 'length' => 32],
            'date' => ['type' => 'varchar', 'length' => 10],
            // Waaseyaa cross-entity references are stored as the destination
            // entity's UUID string (the framework's reference handle; see the
            // migration as-built notes), not an integer id. The column must hold
            // a UUID, so varchar — this stays correct on column-strict backends
            // (MySQL/PostgreSQL for the production deploy), not only on SQLite's
            // dynamic typing.
            'entity_reference' => ['type' => 'varchar', 'length' => (int) ($settings['length'] ?? 255)],
            'integer', 'int' => ['type' => 'int'],
            'boolean', 'bool' => ['type' => 'boolean'],
            'float', 'decimal', 'numeric', 'number' => ['type' => 'float'],
            default => null,
        };

        if ($spec === null) {
            $logger?->warning(
                'SqlSchemaHandler::deriveColumnSpec: unknown field type; using text column. Prefer an explicit match arm.',
                [
                    'entity_type' => $tableName,
                    'field' => $field->getName(),
                    'field_type' => $field->getType(),
                ],
            );
            $spec = ['type' => 'text'];
        }

        $spec['not null'] = (bool) ($settings['not_null'] ?? false);

        if (array_key_exists('default', $settings)) {
            $spec['default'] = $settings['default'];
        } elseif ($field->getDefaultValue() !== null) {
            $spec['default'] = $field->getDefaultValue();
        }

        return $spec;
    }
}
