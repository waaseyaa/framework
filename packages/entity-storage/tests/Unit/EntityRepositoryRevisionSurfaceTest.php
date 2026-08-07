<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriverV2;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\Driver\StorageBoundary;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Revision\RevisionPruningPolicy;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestRevisionableEntity;
use Waaseyaa\Field\FieldStorage;

/**
 * WP-revisions: repository-level surface added for the revision UX —
 * listRevisions(), setCurrentRevision(), pruneRevisions(), backfillInitialRevisions().
 */
#[CoversClass(EntityRepository::class)]
final class EntityRepositoryRevisionSurfaceTest extends TestCase
{
    private DBALDatabase $db;
    private EntityRepository $repo;
    private EntityType $entityType;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();
        $this->entityType = new EntityType(
            id: 'test_revisionable',
            label: 'Test',
            class: TestRevisionableEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
            revisionDefault: true,
            _fieldDefinitions: [
                'flag' => ['type' => 'boolean', 'stored' => FieldStorage::Data],
            ],
        );

        $handler = new SqlSchemaHandler($this->entityType, $this->db);
        $handler->ensureTable();
        $handler->ensureRevisionTable();

        $resolver = new SingleConnectionResolver($this->db);
        $driver = new SqlStorageDriver($resolver);
        $storageBoundary = new StorageBoundary();
        $revisionDriver = new RevisionableStorageDriverV2(
            new RevisionableStorageDriver($resolver, $this->entityType),
            $storageBoundary->driverRowFactory(),
            $storageBoundary->driverSnapshotReader(),
        );

        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnArgument(0);

        $this->repo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $this->entityType,
            $driver,
            $dispatcher,
            $revisionDriver,
            $this->db,
            storageBoundary: $storageBoundary,
        );
    }

    private function createWithEdits(string $id, string ...$titles): void
    {
        $first = array_shift($titles);
        $entity = new TestRevisionableEntity(values: ['title' => $first, 'id' => $id, 'uuid' => 'u' . $id]);
        $entity->enforceIsNew();
        $this->repo->save($entity);

        foreach ($titles as $title) {
            $entity = $this->repo->find($id);
            $entity->set('title', $title);
            $this->repo->save($entity);
        }
    }

    #[Test]
    public function create_with_auto_assigned_id_records_revision_one(): void
    {
        // No pre-set id (auto-increment). The create revision must be recorded
        // keyed on the real assigned id, not orphaned under entity_id ''.
        $e = new TestRevisionableEntity(values: ['title' => 'first', 'uuid' => 'auto-a']);
        $e->set('source_uri', 'public://documents/first.docx');
        $e->enforceIsNew();
        $this->repo->save($e);
        $id = (string) $e->id();
        $this->assertNotSame('', $id, 'id was auto-assigned');

        $e = $this->repo->find($id);
        $e->set('title', 'second');
        $e->set('source_uri', 'public://documents/second.docx');
        $this->repo->save($e);

        $revisions = $this->repo->listRevisions($id);
        $this->assertCount(2, $revisions, 'both the create and the edit recorded a revision');
        $this->assertSame('second', $revisions[0]->label());
        $this->assertSame('public://documents/second.docx', $revisions[0]->get('source_uri'));
        $this->assertSame('first', $revisions[1]->label());
        $this->assertSame('public://documents/first.docx', $revisions[1]->get('source_uri'));
    }

    #[Test]
    public function revisions_carry_non_column_data_fields(): void
    {
        // Fields that are not key columns (folder, source_uri) live in the
        // _data blob. They must round-trip through the revision tables, per
        // version, exactly like the base table. Regression for the sql-blob
        // revisionable gap (revision write previously mapped every field to a
        // column and failed with "no column named folder").
        $e = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $e->set('folder', 'CANCOM');
        $e->set('source_uri', 'public://documents/v1.docx');
        $e->enforceIsNew();
        $this->repo->save($e);

        $e = $this->repo->find('1');
        $e->set('title', 'v2');
        $e->set('source_uri', 'public://documents/v2.docx');
        $this->repo->save($e);

        $revisions = $this->repo->listRevisions('1');
        $this->assertCount(2, $revisions);
        // Newest first, each carrying its own _data fields.
        $this->assertSame('public://documents/v2.docx', $revisions[0]->get('source_uri'));
        $this->assertSame('CANCOM', $revisions[0]->get('folder'));
        $this->assertSame('public://documents/v1.docx', $revisions[1]->get('source_uri'));

        // loadRevision of the older version returns that version's _data.
        $old = $this->repo->loadRevision('1', 1);
        $this->assertNotNull($old);
        $this->assertSame('public://documents/v1.docx', $old->get('source_uri'));
        $this->assertSame('CANCOM', $old->get('folder'));
    }

    #[Test]
    public function list_revisions_returns_history_newest_first(): void
    {
        $this->createWithEdits('1', 'v1', 'v2', 'v3');

        $revisions = $this->repo->listRevisions('1');

        $this->assertCount(3, $revisions);
        $this->assertSame('v3', $revisions[0]->label(), 'newest first');
        $this->assertSame('v2', $revisions[1]->label());
        $this->assertSame('v1', $revisions[2]->label());
    }

    #[Test]
    public function list_revisions_is_empty_for_unknown_entity(): void
    {
        $this->assertSame([], $this->repo->listRevisions('999'));
    }

    #[Test]
    public function set_current_revision_moves_pointer_without_creating_a_revision(): void
    {
        $this->createWithEdits('1', 'v1', 'v2', 'v3');
        $this->assertCount(3, $this->repo->listRevisions('1'));

        $reverted = $this->repo->setCurrentRevision('1', 1);

        $this->assertSame('v1', $reverted->label());
        // The current/default entity now reads v1...
        $this->assertSame('v1', $this->repo->find('1')->label());
        // ...and NO new revision was created (still exactly 3).
        $this->assertCount(3, $this->repo->listRevisions('1'));
    }

    #[Test]
    public function revision_restore_paths_normalize_historical_boolean_values(): void
    {
        $entity = new TestRevisionableEntity(values: [
            'title' => 'v1',
            'id' => '1',
            'uuid' => 'a',
            'flag' => false,
        ]);
        $entity->enforceIsNew();
        $this->repo->save($entity);

        $this->db->getConnection()->update(
            'test_revisionable_revision',
            ['_data' => json_encode(['flag' => true], JSON_THROW_ON_ERROR)],
            ['entity_id' => '1', 'revision_id' => 1],
        );

        $this->repo->setCurrentRevision('1', 1);
        self::assertSame(true, $this->repo->find('1')?->toArray()['flag']);

        $rolledBack = $this->repo->rollback('1', 1);
        self::assertSame(true, $rolledBack->toArray()['flag']);
        self::assertSame(true, $this->repo->find('1')?->toArray()['flag']);
    }

    #[Test]
    public function set_current_revision_rejects_a_missing_revision(): void
    {
        $this->createWithEdits('1', 'v1');
        $this->expectException(\InvalidArgumentException::class);
        $this->repo->setCurrentRevision('1', 999);
    }

    #[Test]
    public function set_current_revision_preserves_the_live_published_pointer_and_status(): void
    {
        // #1920 WP-2 rework fix wave (review finding I-1 / #4 containment):
        // identical containment to rollback() — setCurrentRevision() must
        // restore CONTENT only, never the target revision's frozen
        // published_revision_id/status snapshot over the live base row.
        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->set('status', 0);
        $entity->enforceIsNew();
        $this->repo->save($entity); // revision 1: status=0 (frozen), published_revision_id=null (frozen)

        $entity = $this->repo->find('1');
        $entity->set('title', 'v2');
        $entity->set('status', 1);
        $this->repo->save($entity); // revision 2: status=1; base row status=1

        $this->repo->setPublishedRevision('1', 1);
        $this->assertSame(1, $this->repo->find('1')->get('published_revision_id'));
        $this->assertSame(true, $this->repo->find('1')->get('status'));

        $reverted = $this->repo->setCurrentRevision('1', 1);

        $this->assertSame('v1', $reverted->label(), 'content restored from the target revision');
        $this->assertSame(true, $this->repo->find('1')->get('status'), 'live status untouched');
        $this->assertSame(
            1,
            $this->repo->find('1')->get('published_revision_id'),
            'live published pointer untouched',
        );
    }

    #[Test]
    public function rollback_and_set_current_revision_tolerate_a_legacy_table_without_pointer_or_status_columns(): void
    {
        // Pre-WP-2 base tables have neither published_revision_id nor status
        // as real columns. Both restore paths must behave exactly as before:
        // no SQL error, no key introduced onto the row.
        $legacyType = new EntityType(
            id: 'test_revisionable_legacy_restore',
            label: 'Legacy Restore',
            class: TestRevisionableEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
            revisionDefault: true,
        );

        $this->db->schema()->createTable('test_revisionable_legacy_restore', [
            'fields' => [
                'id' => ['type' => 'serial', 'not null' => true],
                'uuid' => ['type' => 'varchar', 'length' => 128, 'not null' => true, 'default' => ''],
                'bundle' => ['type' => 'varchar', 'length' => 128, 'not null' => true, 'default' => ''],
                'title' => ['type' => 'varchar', 'length' => 255, 'not null' => true, 'default' => ''],
                'langcode' => ['type' => 'varchar', 'length' => 12, 'not null' => true, 'default' => 'en'],
                'revision_id' => ['type' => 'int', 'not null' => false, 'default' => null],
                // NOTE: deliberately no published_revision_id / status columns.
                '_data' => ['type' => 'text', 'not null' => true, 'default' => '{}'],
            ],
            'primary key' => ['id'],
            'indexes' => ['test_revisionable_legacy_restore_bundle' => ['bundle']],
            'unique keys' => ['test_revisionable_legacy_restore_uuid' => ['uuid']],
        ]);

        $handler = new SqlSchemaHandler($legacyType, $this->db);
        $handler->ensureRevisionTable();

        $resolver = new SingleConnectionResolver($this->db);
        $driver = new SqlStorageDriver($resolver);
        $revisionDriver = new RevisionableStorageDriver($resolver, $legacyType);
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnArgument(0);
        $legacyRepo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver($legacyType, $driver, $dispatcher, $revisionDriver, $this->db);

        $this->db->insert('test_revisionable_legacy_restore')
            ->fields(['id', 'uuid', 'title', 'bundle', 'langcode', 'revision_id', '_data'])
            ->values([
                'id' => '1', 'uuid' => 'a', 'title' => 'v2', 'bundle' => 'test_revisionable_legacy_restore',
                'langcode' => 'en', 'revision_id' => 2, '_data' => '{}',
            ])
            ->execute();
        $revisionDriver->writeRevision('1', ['title' => 'v1', 'uuid' => 'a'], null);
        $revisionDriver->writeRevision('1', ['title' => 'v2', 'uuid' => 'a'], null);

        $rolledBack = $legacyRepo->rollback('1', 1);
        $this->assertSame('v1', $rolledBack->label());
        $this->assertNull($legacyRepo->find('1')->get('published_revision_id'));

        $reverted = $legacyRepo->setCurrentRevision('1', 2);
        $this->assertSame('v2', $reverted->label());
        $this->assertNull($legacyRepo->find('1')->get('published_revision_id'));
    }

    #[Test]
    public function prune_revisions_trims_to_policy_and_keeps_current(): void
    {
        $this->createWithEdits('1', 'v1', 'v2', 'v3', 'v4'); // revisions 1..4, current = 4

        $report = $this->repo->pruneRevisions('1', RevisionPruningPolicy::keepLastUniform(2));

        $this->assertSame(2, $report->pruned, 'oldest 2 deleted');
        $remaining = $this->repo->listRevisions('1');
        $this->assertCount(2, $remaining);
        $this->assertSame('v4', $remaining[0]->label());
        $this->assertSame('v3', $remaining[1]->label());
    }

    #[Test]
    public function prune_revisions_never_deletes_the_current_revision(): void
    {
        $this->createWithEdits('1', 'v1', 'v2', 'v3', 'v4');
        // Make an OLD revision current, then prune hard (keep last 1).
        $this->repo->setCurrentRevision('1', 1);

        $report = $this->repo->pruneRevisions('1', RevisionPruningPolicy::keepLastUniform(1));

        // Revision 1 is current → immortal; only revisions 2 and 3 are deletable
        // (revision 4 is the single newest kept).
        $this->assertSame(2, $report->pruned);
        $ids = array_map(static fn($e) => $e->getRevisionId(), $this->repo->listRevisions('1'));
        $this->assertContains(1, $ids, 'current revision survived');
        $this->assertContains(4, $ids, 'newest kept');
    }

    #[Test]
    public function prune_revisions_noop_policy_deletes_nothing(): void
    {
        $this->createWithEdits('1', 'v1', 'v2', 'v3');
        $report = $this->repo->pruneRevisions('1', RevisionPruningPolicy::default());

        $this->assertSame(0, $report->pruned);
        $this->assertCount(3, $this->repo->listRevisions('1'));
    }

    #[Test]
    public function prune_revisions_never_deletes_the_published_revision(): void
    {
        // #1920 WP-2 rework task 5 / review finding #6: the published pointer
        // must be immortal in pruning, exactly like the current pointer, even
        // when it differs from current and a keep-count would otherwise sweep
        // it up as an old candidate.
        $this->createWithEdits('1', 'v1', 'v2', 'v3', 'v4'); // revisions 1..4, current = 4
        $this->repo->setPublishedRevision('1', 2);

        $report = $this->repo->pruneRevisions('1', RevisionPruningPolicy::keepLastUniform(1));

        // newest-kept = [4]; candidates = 1,2,3. Published (2) must survive
        // alongside current (4); only 1 and 3 are actually deletable.
        $this->assertSame(2, $report->pruned);
        $ids = array_map(static fn($e) => $e->getRevisionId(), $this->repo->listRevisions('1'));
        $this->assertContains(2, $ids, 'published revision survived pruning');
        $this->assertContains(4, $ids, 'current revision survived pruning');
        $this->assertNotContains(1, $ids);
        $this->assertNotContains(3, $ids);
    }

    #[Test]
    public function prune_revisions_tolerates_a_base_table_without_the_published_revision_column(): void
    {
        // Legacy (pre-WP-2) base tables never gained the published_revision_id
        // column. pruneRevisions() must behave exactly as before: no SQL
        // error, and the current-revision guard stays intact.
        $legacyType = new EntityType(
            id: 'test_revisionable_legacy_prune',
            label: 'Legacy Prune',
            class: TestRevisionableEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
            revisionDefault: true,
        );

        $this->db->schema()->createTable('test_revisionable_legacy_prune', [
            'fields' => [
                'id' => ['type' => 'serial', 'not null' => true],
                'uuid' => ['type' => 'varchar', 'length' => 128, 'not null' => true, 'default' => ''],
                'bundle' => ['type' => 'varchar', 'length' => 128, 'not null' => true, 'default' => ''],
                'title' => ['type' => 'varchar', 'length' => 255, 'not null' => true, 'default' => ''],
                'langcode' => ['type' => 'varchar', 'length' => 12, 'not null' => true, 'default' => 'en'],
                'revision_id' => ['type' => 'int', 'not null' => false, 'default' => null],
                // NOTE: deliberately no published_revision_id column.
                '_data' => ['type' => 'text', 'not null' => true, 'default' => '{}'],
            ],
            'primary key' => ['id'],
            'indexes' => ['test_revisionable_legacy_prune_bundle' => ['bundle']],
            'unique keys' => ['test_revisionable_legacy_prune_uuid' => ['uuid']],
        ]);

        $handler = new SqlSchemaHandler($legacyType, $this->db);
        $handler->ensureRevisionTable();

        $resolver = new SingleConnectionResolver($this->db);
        $driver = new SqlStorageDriver($resolver);
        $revisionDriver = new RevisionableStorageDriver($resolver, $legacyType);
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnArgument(0);
        $legacyRepo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver($legacyType, $driver, $dispatcher, $revisionDriver, $this->db);

        $this->db->insert('test_revisionable_legacy_prune')
            ->fields(['id', 'uuid', 'title', 'bundle', 'langcode', 'revision_id', '_data'])
            ->values([
                'id' => '1', 'uuid' => 'a', 'title' => 'v4', 'bundle' => 'test_revisionable_legacy_prune',
                'langcode' => 'en', 'revision_id' => 4, '_data' => '{}',
            ])
            ->execute();
        foreach (['v1', 'v2', 'v3', 'v4'] as $title) {
            $revisionDriver->writeRevision('1', ['title' => $title, 'uuid' => 'a'], null);
        }

        $report = $legacyRepo->pruneRevisions('1', RevisionPruningPolicy::keepLastUniform(1));

        // No published pointer exists at all — only the current-revision (4)
        // guard applies, exactly like before this rework: 1,2,3 are all
        // deletable candidates, only 4 (current, and newest-kept) survives.
        $this->assertSame(3, $report->pruned);
        $ids = array_map(static fn($e) => $e->getRevisionId(), $legacyRepo->listRevisions('1'));
        $this->assertContains(4, $ids, 'current revision survived');
        $this->assertCount(1, $ids);
    }

    #[Test]
    public function backfill_initial_revisions_versions_unversioned_rows(): void
    {
        // Simulate a row that predates revisions: write a base row directly with
        // no revision_id and no revision rows.
        $this->db->getConnection()->insert('test_revisionable', [
            'id' => 7,
            'uuid' => 'u7',
            'title' => 'legacy',
            '_data' => '{}',
        ]);
        $this->assertSame([], $this->repo->listRevisions('7'), 'no revisions before backfill');

        $count = $this->repo->backfillInitialRevisions();

        $this->assertSame(1, $count);
        $revisions = $this->repo->listRevisions('7');
        $this->assertCount(1, $revisions);
        $this->assertSame('legacy', $revisions[0]->label());

        // Idempotent: a second backfill does nothing.
        $this->assertSame(0, $this->repo->backfillInitialRevisions());
    }

    #[Test]
    public function backfill_normalizes_historical_boolean_values(): void
    {
        $this->db->getConnection()->insert('test_revisionable', [
            'id' => 8,
            'uuid' => 'u8',
            'title' => 'legacy boolean',
            '_data' => json_encode(['flag' => true], JSON_THROW_ON_ERROR),
        ]);

        self::assertSame(1, $this->repo->backfillInitialRevisions());
        self::assertSame(true, $this->repo->find('8')?->toArray()['flag']);
        self::assertSame(true, $this->repo->loadRevision('8', 1)?->toArray()['flag']);
    }
}
