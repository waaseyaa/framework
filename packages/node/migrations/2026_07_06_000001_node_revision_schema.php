<?php

declare(strict_types=1);

use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;
use Waaseyaa\Node\Node;

/**
 * Retrofits the CW-v1 revision schema onto a `node` table that predates
 * Node opting into `revisionable: true` (WP-2 Task 2.1, commit fa3631206).
 *
 * A fresh install never needs this migration: the kernel-boot
 * SqlSchemaHandler path (buildTableSpec() + ensureRevisionTable()) creates
 * the full revisionable shape — base-row pointer columns and the
 * `node_revision` table — the first time the `node` table is materialized.
 * This migration exists only for a deployment whose `node` table was
 * created before that flip landed, and therefore has neither.
 *
 * Two things are retrofitted, both additive and independently guarded so a
 * half-applied prior run (crash between the two steps) completes cleanly:
 *
 *  1. The base-row pointer columns `revision_id` / `published_revision_id`.
 *     SqlSchemaHandler::buildTableSpec() only emits these at CREATE TABLE
 *     time — SqlSchemaHandler::ensureTable() does not retrofit them onto an
 *     already-existing sql-blob base table. Without the real column,
 *     SqlStorageDriver::write()'s splitForWrite() silently routes
 *     `revision_id` into the `_data` JSON blob instead of a real column
 *     (any value whose column is absent folds into `_data`) — corrupting,
 *     not merely omitting, the pointer semantics that
 *     EntityRepository::setCurrentRevision() / setPublishedRevision() /
 *     backfillInitialRevisions() depend on.
 *  2. The `node_revision` table, via SqlSchemaHandler::ensureRevisionTable()
 *     (:256-268) — the exact idempotent primitive `revisions:enable`
 *     (RevisionsEnableHandler) and EntitySchemaSyncRunner use, so this
 *     migration's table shape can never drift from the live schema-sync
 *     path. A minimal EntityType is constructed here purely to describe
 *     node's keys/shape to the handler; buildRevisionTableSpec() depends
 *     only on entity-type keys for the sql-blob backend (node's backend),
 *     not on the field registry, so this mirrors the real registered node
 *     EntityType (NodeServiceProvider) byte-for-byte.
 *
 * Additive and shape-guarded (media migration pattern,
 * 2026_07_01_000001_add_media_version_vid_unique_index.php): a `node` table
 * that does not exist yet is skipped outright. `down()` is a documented
 * no-op — this is additive schema, not reversible without discarding
 * whatever revision history has already been recorded.
 *
 * **Judgment call (Task 2.2 brief):** this migration is schema-only. It
 * does NOT call EntityRepository::backfillInitialRevisions() to seed an
 * initial revision for pre-existing rows. `Migration::up()` receives only a
 * SchemaBuilder — a thin wrapper over a raw DBAL Connection, with no
 * container, no EntityTypeManager, and no event dispatcher available.
 * backfillInitialRevisions() needs the full write pipeline
 * (StorageDriverInterface + RevisionableStorageDriver +
 * EventDispatcherInterface) and dispatches domain entity-lifecycle events
 * on every backfilled row; hand-assembling that pipeline inside a schema
 * migration would mean a migration runner dispatching entity lifecycle
 * events, a layering mismatch a migration should not take on. This also
 * matches the locked WP-2 design decision (preamble decision 4): the node
 * migration guarantees revision schema only, and backfilling is a
 * documented, operator-run step. Per the runbook (Task 2.7), an operator
 * runs `bin/waaseyaa revisions:enable node` after this migration — which
 * re-ensures the schema (idempotent no-op here) and then backfills initial
 * revisions through the real EntityRepository pipeline.
 */
return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        if (!$schema->hasTable('node')) {
            // Fresh install: the kernel-boot SqlSchemaHandler path creates the
            // full revisionable shape the first time the table is created.
            return;
        }

        $connection = $schema->getConnection();
        $quote = fn(string $id): string => $connection->getDatabasePlatform()->quoteIdentifier($id);

        if (!$schema->hasColumn('node', 'revision_id')) {
            $connection->executeStatement(sprintf(
                'ALTER TABLE %s ADD COLUMN %s INTEGER',
                $quote('node'),
                $quote('revision_id'),
            ));
        }

        if (!$schema->hasColumn('node', 'published_revision_id')) {
            $connection->executeStatement(sprintf(
                'ALTER TABLE %s ADD COLUMN %s INTEGER',
                $quote('node'),
                $quote('published_revision_id'),
            ));
        }

        $nodeEntityType = new EntityType(
            id: 'node',
            label: 'Content',
            class: Node::class,
            keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type', 'revision' => 'revision_id'],
            revisionable: true,
            revisionDefault: true,
        );

        new SqlSchemaHandler($nodeEntityType, new DBALDatabase($connection))->ensureRevisionTable();
    }

    public function down(SchemaBuilder $schema): void
    {
        // Additive schema: no-op on down. Dropping revision_id /
        // published_revision_id / node_revision here would discard any
        // revision history already recorded — an explicit, deliberate
        // data-loss operation an operator must choose, not something a
        // migration rollback silently performs.
    }
};
