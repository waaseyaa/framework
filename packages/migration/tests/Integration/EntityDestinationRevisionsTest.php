<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\Gate\EntityAccessGate;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Migration\MigrationIdMap;
use Waaseyaa\Migration\Plugin\Destination\EntityDestination;
use Waaseyaa\Migration\Plugin\DestinationRecord;
use Waaseyaa\Migration\Schema\MigrationIdMapSchema;
use Waaseyaa\Migration\SourceId;
use Waaseyaa\Migration\Tests\Fixtures\AllowAllPolicy;
use Waaseyaa\Migration\Account\MigrationSystemAccount;
use Waaseyaa\Migration\Tests\Fixtures\MigrationTestRevisionableWidget;
use Waaseyaa\Migration\Tests\Fixtures\MigrationTestWidgetType;

/**
 * Integration coverage for {@see EntityDestination} against the revisionable
 * write path (FR-023, FR-031 — skip vs update on revisionable types).
 *
 * Wires:
 *   - Real {@see DBALDatabase} (in-memory SQLite).
 *   - Real {@see SqlSchemaHandler} → primary + revision tables.
 *   - Real {@see EntityRepository} with {@see RevisionableStorageDriver} injected.
 *   - Real {@see MigrationIdMap} for atomicity (FR-029).
 *
 * Asserts:
 *   - First write creates revision 1.
 *   - Re-run with a changed `source_record_hash` creates revision 2 and updates
 *     the id-map.
 *   - Re-run with an unchanged hash does NOT create a new revision (FR-031).
 */
#[CoversClass(EntityDestination::class)]
#[CoversNothing]
final class EntityDestinationRevisionsTest extends TestCase
{
    private DBALDatabase $db;
    private EntityTypeManager $typeManager;
    private EntityRepository $repository;
    private MigrationIdMap $idMap;
    private EventDispatcher $dispatcher;
    private EntityAccessGate $gate;
    private MigrationSystemAccount $systemAccount;

    private const string MIGRATION_ID = 'migration_test_revisionable_widgets';
    private const string ENTITY_TYPE_ID = 'migration_test_revisionable_widget';

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();
        $conn = $this->db->getConnection();

        $entityType = MigrationTestWidgetType::revisionable();

        // Build the primary + revision tables via the canonical handler.
        $handler = new SqlSchemaHandler($entityType, $this->db);
        $handler->ensureTable();
        $handler->ensureRevisionTable();

        $conn->executeStatement(MigrationIdMapSchema::createTableSql());

        $this->typeManager = new EntityTypeManager(new EventDispatcher());
        $this->typeManager->registerEntityType($entityType);

        $this->dispatcher = new EventDispatcher();

        $resolver = new SingleConnectionResolver($this->db);
        $driver = new SqlStorageDriver($resolver, 'id');
        $revisionDriver = new RevisionableStorageDriver($resolver, $entityType);

        $this->repository = new EntityRepository(
            entityType: $entityType,
            driver: $driver,
            eventDispatcher: $this->dispatcher,
            revisionDriver: $revisionDriver,
            database: $this->db,
        );

        $this->idMap = new MigrationIdMap($this->db);

        $this->systemAccount = new MigrationSystemAccount();
        $this->gate = new EntityAccessGate(
            new EntityAccessHandler([new AllowAllPolicy(self::ENTITY_TYPE_ID)]),
        );
    }

    private function makeDestination(): EntityDestination
    {
        return new EntityDestination(
            destinationEntityTypeId: self::ENTITY_TYPE_ID,
            entityTypeManager: $this->typeManager,
            entityRepository: $this->repository,
            idMap: $this->idMap,
            gate: $this->gate,
            eventDispatcher: $this->dispatcher,
            migrationId: self::MIGRATION_ID,
            account: $this->systemAccount,
        );
    }

    private function makeRecord(string $title): DestinationRecord
    {
        return new DestinationRecord(
            migrationId: self::MIGRATION_ID,
            sourceId: new SourceId(sourceType: 'fake_source', keys: ['key' => 'widget-1']),
            values: ['title' => $title],
        );
    }

    #[Test]
    public function first_write_creates_initial_revision(): void
    {
        $destination = $this->makeDestination();

        $result = $destination->write($this->makeRecord('v1'));

        $loaded = $this->repository->findBy(['uuid' => $result->destinationUuid]);
        self::assertCount(1, $loaded);
        $entity = $loaded[0];
        self::assertInstanceOf(MigrationTestRevisionableWidget::class, $entity);
        self::assertSame('v1', $entity->get('title'));
        self::assertNotNull($entity->getRevisionId(), 'First save must populate a revision id.');
    }

    #[Test]
    public function changed_hash_re_run_creates_new_revision_and_updates_id_map(): void
    {
        $destination = $this->makeDestination();

        $first = $destination->write($this->makeRecord('v1'));
        $revisionsAfterFirst = $this->countRevisionRows();

        $second = $destination->write($this->makeRecord('v2'));
        $revisionsAfterSecond = $this->countRevisionRows();

        // Same destination uuid — update path, not duplicate.
        self::assertSame($first->destinationUuid, $second->destinationUuid);
        self::assertNotSame(
            $first->sourceRecordHash,
            $second->sourceRecordHash,
            'Changed values must advance the source_record_hash (FR-031).',
        );

        $loaded2 = $this->repository->findBy(['uuid' => $second->destinationUuid]);
        self::assertSame('v2', $loaded2[0]->get('title'));

        // FR-023: revisionable types cut a new revision row on update — count
        // the rows in the revision table directly. We assert against the row
        // count rather than the in-memory revision_id because the test wires
        // M-001's RevisionableStorageDriver directly without the
        // EntityRepositoryFactory glue that back-fills the latest revision id
        // onto loaded entities; the on-disk row count is the authoritative
        // signal that a revision was cut.
        self::assertSame(
            $revisionsAfterFirst + 1,
            $revisionsAfterSecond,
            'FR-023: revisionable types must persist a new revision row on changed re-run.',
        );

        // Id-map hash advanced too (FR-029, FR-031).
        $row = $this->idMap->lookupDestination(
            self::MIGRATION_ID,
            new SourceId(sourceType: 'fake_source', keys: ['key' => 'widget-1']),
        );
        self::assertNotNull($row);
        self::assertSame($second->sourceRecordHash, $row->sourceRecordHash);
    }

    private function countRevisionRows(): int
    {
        $conn = $this->db->getConnection();
        $count = $conn->fetchOne('SELECT COUNT(*) FROM migration_test_revisionable_widget_revision');

        return (int) $count;
    }

    #[Test]
    public function unchanged_hash_re_run_skips_save_and_does_not_create_a_new_revision(): void
    {
        $destination = $this->makeDestination();

        $first = $destination->write($this->makeRecord('v1'));
        $revisionsAfterFirst = $this->countRevisionRows();

        // Re-run with identical hash → must be a no-op (FR-031).
        $skipped = $destination->write($this->makeRecord('v1'));
        $revisionsAfterSkip = $this->countRevisionRows();

        self::assertSame($first->destinationUuid, $skipped->destinationUuid);
        self::assertSame(
            $revisionsAfterFirst,
            $revisionsAfterSkip,
            'FR-031: skip path must NOT cut a new revision when source_record_hash is unchanged.',
        );
    }
}
