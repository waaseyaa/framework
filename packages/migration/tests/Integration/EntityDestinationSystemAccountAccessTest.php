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
use Waaseyaa\Access\Policy\ContentAdminAccessPolicy;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\Migration\Account\MigrationSystemAccount;
use Waaseyaa\Migration\Exception\DestinationWriteException;
use Waaseyaa\Migration\MigrationIdMap;
use Waaseyaa\Migration\Plugin\Destination\EntityDestination;
use Waaseyaa\Migration\Plugin\DestinationRecord;
use Waaseyaa\Migration\Schema\MigrationIdMapSchema;
use Waaseyaa\Migration\SourceId;
use Waaseyaa\Migration\Tests\Fixtures\MigrationTestWidget;

/**
 * Integration proof for G-023: {@see MigrationSystemAccount} is a real,
 * production-safe account that the REAL access stack — a real
 * {@see EntityAccessGate} + {@see EntityAccessHandler} wired with the
 * framework-default {@see ContentAdminAccessPolicy} — actually grants or
 * denies, unlike the allow-all fixture it replaces in the other
 * `EntityDestination` integration tests.
 *
 * `ContentAdminAccessPolicy` grants full manage/create on any entity type in
 * the `content` group to an account holding `administer content`
 * ({@see ContentAdminAccessPolicy::ADMIN_PERMISSION}) — exactly
 * `MigrationSystemAccount::DEFAULT_PERMISSIONS`. This is the closest
 * real-policy proof available in-repo without wiring a bundle-specific
 * policy like `NodeAccessPolicy`.
 */
#[CoversClass(EntityDestination::class)]
#[CoversNothing]
final class EntityDestinationSystemAccountAccessTest extends TestCase
{
    private const string MIGRATION_ID = 'migration_test_system_account';
    private const string ENTITY_TYPE_ID = 'migration_test_widget_content_group';

    private DBALDatabase $db;
    private EntityTypeManager $typeManager;
    private EntityRepository $repository;
    private MigrationIdMap $idMap;
    private EventDispatcher $dispatcher;
    private EntityAccessGate $gate;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();
        $conn = $this->db->getConnection();

        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS "' . self::ENTITY_TYPE_ID . '" ('
            . '"id" INTEGER PRIMARY KEY AUTOINCREMENT, '
            . '"uuid" TEXT, '
            . '"title" TEXT, '
            . '"_data" TEXT DEFAULT \'{}\''
            . ')',
        );

        $conn->executeStatement(MigrationIdMapSchema::createTableSql());

        // Real entity type in the `content` group — the exact group
        // ContentAdminAccessPolicy::appliesTo() checks.
        $entityType = new EntityType(
            id: self::ENTITY_TYPE_ID,
            label: 'Migration Test Widget (content group)',
            class: MigrationTestWidget::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
            group: ContentAdminAccessPolicy::CONTENT_GROUP,
        );

        $this->typeManager = new EntityTypeManager(new EventDispatcher());
        $this->typeManager->registerEntityType($entityType);

        $this->dispatcher = new EventDispatcher();

        $resolver = new SingleConnectionResolver($this->db);
        $driver = new SqlStorageDriver($resolver, 'id');

        $this->repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            entityType: $entityType,
            driver: $driver,
            eventDispatcher: $this->dispatcher,
        );

        $this->idMap = new MigrationIdMap($this->db);

        // Real policy, real handler, real gate — no allow-all/forbid-all fixture.
        $this->gate = new EntityAccessGate(
            new EntityAccessHandler([new ContentAdminAccessPolicy($this->typeManager)]),
        );
    }

    private function makeDestination(object $account): EntityDestination
    {
        return new EntityDestination(
            destinationEntityTypeId: self::ENTITY_TYPE_ID,
            entityTypeManager: $this->typeManager,
            entityRepository: $this->repository,
            idMap: $this->idMap,
            gate: $this->gate,
            eventDispatcher: $this->dispatcher,
            migrationId: self::MIGRATION_ID,
            account: $account,
        );
    }

    #[Test]
    public function default_migration_system_account_is_granted_create_by_content_admin_policy(): void
    {
        $destination = $this->makeDestination(new MigrationSystemAccount());

        $result = $destination->write(new DestinationRecord(
            migrationId: self::MIGRATION_ID,
            sourceId: new SourceId(sourceType: 'fake_source', keys: ['key' => 'granted']),
            values: ['title' => 'Granted via administer content'],
        ));

        self::assertSame(self::ENTITY_TYPE_ID, $result->destinationEntityType);

        $loaded = $this->repository->findBy(['uuid' => $result->destinationUuid]);
        self::assertCount(1, $loaded);
        self::assertSame('Granted via administer content', $loaded[0]->get('title'));
    }

    #[Test]
    public function migration_system_account_without_the_permission_is_denied_create(): void
    {
        // Least-privilege proof: an account explicitly stripped of the
        // permission is denied by the REAL policy, not a test fixture.
        $destination = $this->makeDestination(new MigrationSystemAccount([]));

        try {
            $destination->write(new DestinationRecord(
                migrationId: self::MIGRATION_ID,
                sourceId: new SourceId(sourceType: 'fake_source', keys: ['key' => 'denied']),
                values: ['title' => 'Should not be written'],
            ));
            self::fail('Expected DestinationWriteException for a MigrationSystemAccount lacking "administer content".');
        } catch (DestinationWriteException $e) {
            self::assertSame('entity_create_denied', $e->reason);
            self::assertSame(self::ENTITY_TYPE_ID, $e->destinationEntityType);
        }

        $row = $this->idMap->lookupDestination(
            self::MIGRATION_ID,
            new SourceId(sourceType: 'fake_source', keys: ['key' => 'denied']),
        );
        self::assertNull($row, 'No id-map row should exist after a denied write.');
    }
}
