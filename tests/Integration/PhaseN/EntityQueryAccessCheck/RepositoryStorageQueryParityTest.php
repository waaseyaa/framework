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
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Exception\MissingQueryAccountException;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;
use Waaseyaa\User\AnonymousUser;

/**
 * C-22 — Parity: `EntityRepository::getQuery()` is the SAME access-checked
 * query surface as `SqlEntityStorage::getQuery()`.
 *
 * The canonical repository engine gains an access-checked query surface so the
 * legacy `SqlEntityStorage` engine can eventually be removed (issue C-22, see
 * `docs/notes/c22-consumer-inventory.md`). This test pins the contract: given
 * the SAME database, entity type, and {@see EntityAccessHandler}, the two
 * `getQuery()` surfaces must be behaviourally identical —
 *
 *   1. fail-closed the same way (no bound account → {@see MissingQueryAccountException});
 *   2. return the SAME access-filtered id set for an account-bound query.
 *
 * Both engines read the same rows from one shared SQLite database, so any
 * divergence in the query build, the access-handler threading, or the
 * per-row filter surfaces here.
 */
#[CoversNothing]
final class RepositoryStorageQueryParityTest extends TestCase
{
    private DBALDatabase $database;
    private EntityAccessHandler $accessHandler;
    private SqlEntityStorage $storage;
    private EntityRepository $repository;

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

        // ONE shared access handler drives BOTH engines — strongest parity.
        $this->accessHandler = new EntityAccessHandler([$this->publishedOnlyForAnonymousPolicy()]);

        $this->storage = new SqlEntityStorage(
            $entityType,
            $this->database,
            new EventDispatcher(),
            accessHandler: $this->accessHandler,
        );

        $resolver = new SingleConnectionResolver($this->database);
        $driver = new SqlStorageDriver($resolver, 'id');
        $this->repository = new EntityRepository(
            $entityType,
            $driver,
            new EventDispatcher(),
            database: $this->database,
            accessHandler: $this->accessHandler,
        );

        // Seed via the storage engine; both engines read the same table.
        foreach ([
            ['title' => 'p1', 'status' => 'published'],
            ['title' => 'p2', 'status' => 'published'],
            ['title' => 'd1', 'status' => 'draft'],
            ['title' => 'd2', 'status' => 'draft'],
        ] as $row) {
            $this->storage->save($this->storage->create($row));
        }
    }

    #[Test]
    public function repositoryGetQueryFailsClosedWithoutAccountLikeStorage(): void
    {
        // Storage surface fails closed (baseline).
        $storageThrew = false;
        try {
            $this->storage->getQuery()->execute();
        } catch (MissingQueryAccountException) {
            $storageThrew = true;
        }
        $this->assertTrue($storageThrew, 'baseline: storage getQuery() fails closed without an account');

        // Repository surface MUST fail closed identically.
        $this->expectException(MissingQueryAccountException::class);
        $this->repository->getQuery()->execute();
    }

    #[Test]
    public function repositoryGetQueryReturnsSameAccessFilteredRowsAsStorage(): void
    {
        $anon = new AnonymousUser();

        $storageIds = $this->storage->getQuery()
            ->setAccount($anon)
            ->sort('id', 'ASC')
            ->execute();

        $repositoryIds = $this->repository->getQuery()
            ->setAccount(new AnonymousUser())
            ->sort('id', 'ASC')
            ->execute();

        // Parity: identical id set, identical order.
        $this->assertSame($storageIds, $repositoryIds, 'both getQuery() surfaces return the same access-filtered ids');

        // And the filter actually ran: exactly the 2 published rows survive.
        $this->assertCount(2, $repositoryIds);
        $this->assertSame(
            ['p1', 'p2'],
            $this->titlesFor($repositoryIds),
            'repository query drops the draft rows the anonymous policy forbids',
        );
    }

    /**
     * @param list<int|string> $ids
     * @return list<string>
     */
    private function titlesFor(array $ids): array
    {
        $entities = $this->storage->loadMultiple($ids);
        $titles = [];
        foreach ($ids as $id) {
            $entity = $entities[$id] ?? null;
            if ($entity instanceof EntityInterface) {
                $title = $entity->get('title');
                $titles[] = is_string($title) ? $title : '';
            }
        }

        return $titles;
    }

    /**
     * Published rows are viewable by anyone; draft rows are forbidden for
     * anonymous. (Mirrors the AnonymousAccountFilterTest policy so the two
     * surfaces are compared against a known-good filter.)
     */
    private function publishedOnlyForAnonymousPolicy(): AccessPolicyInterface
    {
        return new class implements AccessPolicyInterface {
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return $entity->get('status') === 'published'
                    ? AccessResult::allowed('public read')
                    : AccessResult::forbidden('draft not visible to anonymous');
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
