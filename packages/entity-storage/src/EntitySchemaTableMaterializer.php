<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Migration\EntityTableMaterializerInterface;

/**
 * The canonical entity-schema materializer, exposed to the migration runtime.
 *
 * Answers exactly one question for the Migrator: of the tables this plan names,
 * which are registered entity **base** tables that do not exist yet? Those are
 * created through the same {@see EntitySchemaSync} path `schema:sync` uses, so
 * there is one authority for entity table shape, not two.
 *
 * Deliberate restrictions:
 *
 * - **Base tables only.** Ownership is keyed on `EntityTypeInterface::id()`.
 *   Bundle subtables are excluded because `{base}__{bundle}` is materialized only
 *   when a field registry is wired and the bundle carries at least one field, so
 *   claiming them would promise something synchronization does not always do.
 *   Revision and translation siblings are excluded for the same reason.
 * - **Never touches an existing table.** Absence is the only trigger. An existing
 *   table's evolution belongs to the migration, in its declared order.
 * - **Unowned tables are left absent** so the migration fails closed on a real
 *   SQL error rather than on a guess about intent.
 *
 * The definitions provider is a callable because composition sites build the
 * migration runtime before entity types finish registering; it is resolved at
 * materialize time, not at construction.
 *
 * @see docs/change-records/FW-2701.md — C1 targeted materialization
 * @api
 */
final class EntitySchemaTableMaterializer implements EntityTableMaterializerInterface
{
    /** @var (\Closure(): iterable<int|string, EntityTypeInterface>) */
    private readonly \Closure $definitions;

    /**
     * @param (\Closure(): iterable<int|string, EntityTypeInterface>) $definitions
     */
    public function __construct(
        private readonly DatabaseInterface $database,
        \Closure $definitions,
        private readonly ?FieldDefinitionRegistryInterface $fieldRegistry = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->definitions = $definitions;
    }

    public function materialize(array $tables): array
    {
        if ($tables === []) {
            return [];
        }

        $owned = [];
        foreach (($this->definitions)() as $type) {
            $owned[$type->id()] = $type;
        }
        if ($owned === []) {
            return [];
        }

        $schema = $this->database->schema();
        $toSync = [];
        $created = [];
        foreach ($tables as $table) {
            if (!isset($owned[$table]) || $schema->tableExists($table)) {
                continue;
            }
            $toSync[] = $owned[$table];
            $created[] = $table;
        }

        if ($toSync !== []) {
            new EntitySchemaSync(
                $this->database,
                $this->fieldRegistry,
                null,
                $this->logger,
            )->syncAll($toSync);
        }

        return $created;
    }
}
