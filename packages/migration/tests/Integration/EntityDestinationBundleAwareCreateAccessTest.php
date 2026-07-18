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
use Waaseyaa\Node\Node;
use Waaseyaa\Node\NodeAccessPolicy;

/**
 * Integration proof for GitHub #1946: a bundle-aware create subject flows
 * from {@see EntityDestination} through the REAL {@see EntityAccessGate} +
 * {@see EntityAccessHandler} + {@see NodeAccessPolicy}, so a least-privilege
 * {@see MigrationSystemAccount} holding ONLY `create article content` (no
 * `administer content`/`administer nodes`) is granted create for bundle
 * `article` and denied for bundle `page`.
 *
 * Before #1946, `EntityAccessGate` hardcoded bundle `''` on every string
 * create-subject call, so `NodeAccessPolicy::createAccess()` always checked
 * `"create  content"` (empty bundle) — a permission string no per-bundle
 * grant could ever match. `EntityDestination::buildCreateSubject()` now
 * passes the array subject `['entity_type' => 'node', 'bundle' => $bundle]`
 * whenever the destination entity type has a bundle key and the record
 * declares one, letting real per-bundle policies participate in import
 * writes.
 */
#[CoversClass(EntityDestination::class)]
#[CoversClass(EntityAccessGate::class)]
#[CoversNothing]
final class EntityDestinationBundleAwareCreateAccessTest extends TestCase
{
    private const string MIGRATION_ID = 'migration_test_bundle_aware';

    private DBALDatabase $db;
    private EntityTypeManager $typeManager;
    private EntityRepository $repository;
    private MigrationIdMap $idMap;
    private EventDispatcher $dispatcher;
    private EntityAccessGate $gate;

    protected function setUp(): void
    {
        // fromClass() is first-call-wins per class; this test builds Node's
        // EntityType with defaults (non-revisionable), which must not leak
        // into later tests that expect the revisionable wiring (e.g.
        // NodeRevisionDefaultWiringTest) — clear on both edges.
        EntityType::clearFromClassCache();

        $this->db = DBALDatabase::createSqlite();
        $conn = $this->db->getConnection();

        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS "node" ('
            . '"nid" INTEGER PRIMARY KEY AUTOINCREMENT, '
            . '"uuid" TEXT, '
            . '"title" TEXT, '
            . '"type" TEXT, '
            . '"_data" TEXT DEFAULT \'{}\''
            . ')',
        );

        $conn->executeStatement(MigrationIdMapSchema::createTableSql());

        $entityType = EntityType::fromClass(Node::class);

        $this->typeManager = new EntityTypeManager(new EventDispatcher());
        $this->typeManager->registerEntityType($entityType);

        $this->dispatcher = new EventDispatcher();

        $resolver = new SingleConnectionResolver($this->db);
        $driver = new SqlStorageDriver($resolver, 'nid');

        $this->repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::create(
            entityType: $entityType,
            driver: $driver,
            eventDispatcher: $this->dispatcher,
        );

        $this->idMap = new MigrationIdMap($this->db);

        // Real policy, real handler, real gate.
        $this->gate = new EntityAccessGate(
            new EntityAccessHandler([new NodeAccessPolicy()]),
        );
    }


    protected function tearDown(): void
    {
        EntityType::clearFromClassCache();
    }

    private function makeDestination(object $account): EntityDestination
    {
        return new EntityDestination(
            destinationEntityTypeId: 'node',
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
    public function account_with_only_create_article_content_writes_article_bundle(): void
    {
        $account = new MigrationSystemAccount(['create article content']);
        $destination = $this->makeDestination($account);

        $result = $destination->write(new DestinationRecord(
            migrationId: self::MIGRATION_ID,
            sourceId: new SourceId(sourceType: 'fake_source', keys: ['key' => 'article-1']),
            values: ['title' => 'An article'],
            bundle: 'article',
        ));

        self::assertSame('node', $result->destinationEntityType);

        $loaded = $this->repository->findBy(['uuid' => $result->destinationUuid]);
        self::assertCount(1, $loaded);
        self::assertSame('article', $loaded[0]->get('type'));
    }

    #[Test]
    public function account_with_only_create_article_content_is_denied_for_page_bundle(): void
    {
        $account = new MigrationSystemAccount(['create article content']);
        $destination = $this->makeDestination($account);

        try {
            $destination->write(new DestinationRecord(
                migrationId: self::MIGRATION_ID,
                sourceId: new SourceId(sourceType: 'fake_source', keys: ['key' => 'page-1']),
                values: ['title' => 'A page'],
                bundle: 'page',
            ));
            self::fail('Expected DestinationWriteException: "create article content" must not grant bundle "page".');
        } catch (DestinationWriteException $e) {
            self::assertSame('entity_create_denied', $e->reason);
            self::assertSame('node', $e->destinationEntityType);
        }

        $row = $this->idMap->lookupDestination(
            self::MIGRATION_ID,
            new SourceId(sourceType: 'fake_source', keys: ['key' => 'page-1']),
        );
        self::assertNull($row, 'No id-map row should exist after a denied write.');
    }
}
