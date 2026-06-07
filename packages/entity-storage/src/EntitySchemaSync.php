<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\EntityStorage\Backend\ReservedBackendIds;
use Waaseyaa\EntityStorage\Schema\TranslationSchemaHandler;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Foundation\Log\LoggerInterface;

/**
 * Materializes entity-storage tables for a list of entity types.
 *
 * Thin wrapper around SqlSchemaHandler::ensureTable() that lets migrations
 * (or install/schema-sync commands) sync schemas for many entity types at once
 * without each caller repeating the construction boilerplate.
 *
 * When a {@see FieldDefinitionRegistryInterface} is supplied, the handler also
 * materializes per-bundle subtables (`{base_table}__{bundle}`) for multi-bundle
 * entity types — matching the kernel's lazy storage-factory path. Without it,
 * bundle subtables are skipped (status-quo behaviour, kept for the existing
 * registry-less call sites).
 *
 * Translatable storage layout (FR-020..FR-025):
 *   - `sql-blob` translatable types fold per-langcode rows directly into the
 *     base table (PK widened to (entity_id, langcode), `default_langcode`
 *     column, partial UNIQUE on uuid). No companion `_translations` table is
 *     materialized — the base table IS the translation surface.
 *   - `sql-column` translatable types (WP05) keep a `<table>__translation`
 *     side-table for translatable columns; non-translatable columns stay on
 *     the base table. WP05 wires that branch.
 * @api
 */
final class EntitySchemaSync
{
    /**
     * @param \Closure|null $bundleEnumerator Optional fn(EntityTypeInterface): iterable<string>
     *   forwarded to SqlSchemaHandler for bundle discovery (defaults to the
     *   field registry's bundleNamesFor() when null and a registry is set).
     */
    public function __construct(
        private readonly DatabaseInterface $database,
        private readonly ?FieldDefinitionRegistryInterface $fieldRegistry = null,
        private readonly ?\Closure $bundleEnumerator = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * @param iterable<EntityTypeInterface> $entityTypes
     */
    public function syncAll(iterable $entityTypes): void
    {
        foreach ($entityTypes as $entityType) {
            $backend = $this->resolveBackend($entityType);
            // For sql-column translatable types, the primary table carries
            // only the non-translatable subset of fields (FR-027). Translatable
            // columns live on `<table>__translation`, owned by TranslationSchemaHandler.
            $entityLevelFields = [];
            if ($backend === ReservedBackendIds::SQL_COLUMN) {
                foreach ($entityType->getFieldDefinitions() as $name => $definition) {
                    if (!$definition instanceof FieldDefinition) {
                        continue;
                    }
                    if ($entityType->isTranslatable() && $definition->isTranslatable()) {
                        continue;
                    }
                    $entityLevelFields[$name] = $definition;
                }
            }
            $handler = new SqlSchemaHandler(
                entityType: $entityType,
                database: $this->database,
                fieldRegistry: $this->fieldRegistry,
                bundleEnumerator: $this->bundleEnumerator,
                logger: $this->logger,
                primaryBackendId: $backend,
                entityLevelFields: $entityLevelFields,
            );
            $handler->ensureTable();

            // sql-blob translatable: per-langcode rows live IN the base table
            // (FR-020). No side-table is materialised.
            // sql-column translatable: WP05's TranslationSchemaHandler owns
            // the `<table>__translation` sibling table (FR-026..FR-031).
            // Any other (non-sql-column, non-sql-blob) backend still uses the
            // legacy `<table>_translations` shape via SqlSchemaHandler.
            if ($entityType->isTranslatable()) {
                if ($backend === ReservedBackendIds::SQL_COLUMN) {
                    $translationHandler = new TranslationSchemaHandler($this->database);
                    $translationHandler->sync($entityType);
                } elseif ($backend !== ReservedBackendIds::SQL_BLOB) {
                    $handler->ensureTranslationTable();
                }
            }

            if ($entityType->isRevisionable()) {
                $handler->ensureRevisionTable();
            }
        }
    }

    /**
     * Resolve the primary storage backend id for an entity type.
     *
     * Honours the entity-type's explicit `primaryStorageBackend` declaration;
     * falls back to the framework default (`sql-blob`) when unset.
     */
    private function resolveBackend(EntityTypeInterface $entityType): string
    {
        $declared = $entityType->getPrimaryStorageBackend();
        if (\is_string($declared) && $declared !== '') {
            return $declared;
        }
        return ReservedBackendIds::SQL_BLOB;
    }
}
