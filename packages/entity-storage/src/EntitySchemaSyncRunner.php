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
 * {@see SchemaSyncReport} describing which tables were created, which already
 * existed, and which of those already-existing tables gained new columns or
 * indexes. A table existing before the run is not the same as that table
 * needing no work: fields (and their indexes) registered since the last sync
 * are additively materialized onto it, and the report says so (#2732).
 * Idempotent: a true no-op run reports an empty `created` and an empty
 * `altered`.
 *
 * On a platform where the underlying preview mechanism cannot safely
 * introspect without mutating (anything other than SQLite, or a connection
 * where a mutation is already active), whether an already-existing table
 * needs additive work cannot be determined ahead of applying it. Those
 * entity-type ids are reported in {@see SchemaSyncReport::$indeterminate}
 * instead of being folded into `altered` — a genuine "not previewable" is
 * not the same claim as "will be altered" (#2732).
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
        /** @var list<EntityTypeInterface> $preExisting */
        $preExisting = [];

        foreach ($definitions as $type) {
            $tableName = $type->id();
            if ($schema->tableExists($tableName)) {
                $existing[] = $tableName;
                $preExisting[] = $type;
            } else {
                $created[] = $tableName;
            }
            $toSync[] = $type;
        }

        sort($created);
        sort($existing);

        $sync = new EntitySchemaSync(
            $this->database,
            $this->fieldRegistry,
            null,
            $this->logger,
        );

        // Preview and apply must describe the real materialization work, not
        // merely whether the base table exists (#2732): a table already
        // present can still gain columns/indexes for fields registered since
        // the last sync. Derive that from the actual sync traversal, run
        // read-only, rather than a second hand-maintained model of what would
        // change — the same rule applies whether this is a dry run or an
        // apply, so the two cannot disagree. When the platform cannot support
        // that read-only preview (non-SQLite, or a mutation already active),
        // the affected ids are reported as indeterminate rather than folded
        // into `altered` — see the class docblock.
        if ($preExisting === []) {
            $altered = [];
            $indeterminate = [];
        } else {
            $plan = $sync->planMutatingEntityTypeIds($preExisting);
            $altered = $plan->mutating;
            $indeterminate = $plan->indeterminate;
        }
        sort($altered);
        sort($indeterminate);

        if (!$dryRun && $toSync !== []) {
            $sync->syncAll($toSync);
        }

        return new SchemaSyncReport($created, $existing, $dryRun, $altered, $indeterminate);
    }
}
