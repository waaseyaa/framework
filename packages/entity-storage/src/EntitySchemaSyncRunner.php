<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;

/**
 * Discovery-driven, reporting wrapper around {@see EntitySchemaSync}.
 *
 * Enumerates a set of registered entity-type definitions, materializes every
 * one's storage schema (base table, translation/revision tables, and — when a
 * field registry is supplied — per-bundle subtables), and returns a
 * {@see SchemaSyncReport} describing which tables were created vs already
 * present. Idempotent: a second run reports an empty `created` set.
 *
 * This is the hardened path the `schema:sync` command and `db:init
 * --sync-schema` share, closing the gap where app-defined entity types are
 * registered but never get a table until first lazy access.
 *
 * @api
 */
final class EntitySchemaSyncRunner
{
    public function __construct(
        private readonly DatabaseInterface $database,
        private readonly ?FieldDefinitionRegistryInterface $fieldRegistry = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * @param iterable<int|string, EntityTypeInterface> $definitions
     */
    public function run(iterable $definitions, bool $dryRun = false): SchemaSyncReport
    {
        $schema = $this->database->schema();

        $created = [];
        $existing = [];
        /** @var list<EntityTypeInterface> $toSync */
        $toSync = [];

        foreach ($definitions as $type) {
            $tableName = $type->id();
            if ($schema->tableExists($tableName)) {
                $existing[] = $tableName;
            } else {
                $created[] = $tableName;
            }
            $toSync[] = $type;
        }

        sort($created);
        sort($existing);

        if (!$dryRun && $toSync !== []) {
            new EntitySchemaSync(
                $this->database,
                $this->fieldRegistry,
                null,
                $this->logger,
            )->syncAll($toSync);
        }

        return new SchemaSyncReport($created, $existing, $dryRun);
    }
}
