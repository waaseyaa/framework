<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseN\EntityQueryAccessCheck;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;

/**
 * T017 — Integration: JSON:API `GET /api/{entity-type}` returns only
 * access-allowed rows, AND `meta.total` reflects the filtered cardinality
 * (not the unfiltered candidate window).
 *
 * Covers SC-001 of mission `sql-entity-query-access-checking-01KRYP15`,
 * exercised through the {@see JsonApiController::index()} pipeline on a
 * real {@see EntityRepository} substrate (so the WP02 filter actually
 * runs against the SQL-side row stream).
 *
 * Seeds 6 entities (2 owned by A, 2 by B, 2 owned by nobody). Issues an
 * index request as account A. Asserts:
 *   - response body contains exactly 2 IDs (A's two rows);
 *   - response titles are exactly the two A-owned titles;
 *   - `meta.total === 2` (NOT 6 — the pre-WP02 bug was that meta.total
 *     leaked unfiltered cardinality).
 */
#[CoversNothing]
final class ListingFilterTest extends TestCase
{
    private const ACCOUNT_A_ID = 10;
    private const ACCOUNT_B_ID = 20;

    private DBALDatabase $database;
    private EntityRepository $repository;
    private EntityTypeManager $entityTypeManager;
    private EntityAccessHandler $accessHandler;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $entityType = new EntityType(
            id: 'article',
            label: 'Article',
            class: TestStorageEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
        );

        new SqlSchemaHandler($entityType, $this->database)->ensureTable();

        $eventDispatcher = new EventDispatcher();
        $this->accessHandler = new EntityAccessHandler([$this->ownerOnlyPolicy()]);

        $resolver = new SingleConnectionResolver($this->database);
        $database = $this->database;
        $accessHandler = $this->accessHandler;
        $this->repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $entityType,
            new SqlStorageDriver($resolver),
            $eventDispatcher,
            database: $database,
            accessHandler: $accessHandler,
        );

        $this->entityTypeManager = new EntityTypeManager(
            $eventDispatcher,
            // C-22: repository factory mirroring the kernel's getRepository() shape.
            repositoryFactory: static fn(string $_id, EntityTypeInterface $type): EntityRepository => \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
                $type,
                new SqlStorageDriver($resolver),
                $eventDispatcher,
                database: $database,
                accessHandler: $accessHandler,
            ),
        );
        $this->entityTypeManager->registerEntityType($entityType);

        // 2 owned by A, 2 owned by B, 2 with no owner — for everyone except
        // an admin (not tested here), no-owner rows are Forbidden.
        $rows = [
            ['title' => 'a1', 'owner_id' => self::ACCOUNT_A_ID],
            ['title' => 'a2', 'owner_id' => self::ACCOUNT_A_ID],
            ['title' => 'b1', 'owner_id' => self::ACCOUNT_B_ID],
            ['title' => 'b2', 'owner_id' => self::ACCOUNT_B_ID],
            ['title' => 'n1', 'owner_id' => 0],
            ['title' => 'n2', 'owner_id' => 0],
        ];
        foreach ($rows as $row) {
            $this->repository->save($this->repository->create($row), validate: false);
        }
    }

    #[Test]
    public function indexReturnsOnlyAccountAOwnedRows(): void
    {
        $accountA = $this->makeAccount(self::ACCOUNT_A_ID);
        $controller = new JsonApiController(
            $this->entityTypeManager,
            new ResourceSerializer($this->entityTypeManager),
            $this->accessHandler,
            $accountA,
        );

        $document = $controller->index('article');

        $this->assertSame(200, $document->statusCode);
        $body = $document->toArray();

        // The response body contains exactly 2 resources — a1, a2.
        $this->assertArrayHasKey('data', $body);
        $this->assertIsArray($body['data']);
        $this->assertCount(2, $body['data'], 'index returns only the 2 rows account A owns');

        $titles = array_map(static fn(array $resource): string => $resource['attributes']['title'] ?? '', $body['data']);
        sort($titles);
        $this->assertSame(['a1', 'a2'], $titles);
    }

    #[Test]
    public function indexMetaTotalReflectsFilteredCardinality(): void
    {
        $accountA = $this->makeAccount(self::ACCOUNT_A_ID);
        $controller = new JsonApiController(
            $this->entityTypeManager,
            new ResourceSerializer($this->entityTypeManager),
            $this->accessHandler,
            $accountA,
        );

        $document = $controller->index('article');
        $body = $document->toArray();

        // Bug-fix lock for SC-001: pre-WP02, meta.total reported the
        // unfiltered count (6). Post-WP02, the count query goes through
        // SqlEntityQuery with the bound account and returns 2.
        $this->assertArrayHasKey('meta', $body);
        $this->assertSame(2, $body['meta']['total'], 'meta.total reflects post-filter cardinality');
    }

    private function makeAccount(int $id): AccountInterface
    {
        return new AuthorizationPrincipal($id, $id !== 0, [], [], 'test-' . $id);
    }

    /**
     * Policy: `allowed` when account owns the row, `forbidden` otherwise.
     */
    private function ownerOnlyPolicy(): AccessPolicyInterface
    {
        return new class implements AccessPolicyInterface {
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                $ownerId = $entity->get('owner_id');
                if (is_int($ownerId) && $ownerId === $account->id() && $ownerId !== 0) {
                    return AccessResult::allowed('owner match');
                }
                return AccessResult::forbidden('owner mismatch');
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }

            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === 'article';
            }
        };
    }
}
