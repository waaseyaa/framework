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
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;
use Waaseyaa\User\AnonymousUser;

/**
 * T020 — Integration: anonymous accounts are a first-class bound principal.
 *
 * Covers SC-004 (anonymous request flow) of mission
 * `sql-entity-query-access-checking-01KRYP15`.
 *
 * Seeds 2 published + 2 draft rows. Policy: anonymous can `view` published,
 * cannot `view` draft. Binds {@see AnonymousUser} (id=0) and asserts that
 * the query returns exactly the 2 published IDs.
 *
 * Two things this test pins:
 *   1. `AnonymousUser` is a valid bound account — does NOT trigger
 *      `MissingQueryAccountException`. (id=0 is a legitimate sentinel,
 *      not a "no account" marker.)
 *   2. The query layer filter respects the policy's per-row Forbidden
 *      verdict for anonymous, dropping draft rows from the result set.
 */
#[CoversNothing]
final class AnonymousAccountFilterTest extends TestCase
{
    private DBALDatabase $database;
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

        $resolver = new SingleConnectionResolver($this->database);
        $driver = new SqlStorageDriver($resolver);
        $this->repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $entityType,
            $driver,
            new EventDispatcher(),
            database: $this->database,
            accessHandler: new EntityAccessHandler([$this->publishedOnlyForAnonymousPolicy()]),
        );

        foreach ([
            ['title' => 'p1', 'status' => 'published'],
            ['title' => 'p2', 'status' => 'published'],
            ['title' => 'd1', 'status' => 'draft'],
            ['title' => 'd2', 'status' => 'draft'],
        ] as $row) {
            $this->repository->save($this->repository->create($row), validate: false);
        }
    }

    #[Test]
    public function anonymousSeesPublishedOnly(): void
    {
        $ids = $this->repository->getQuery()
            ->setAccount(new AnonymousUser())
            ->sort('id', 'ASC')
            ->execute();

        $this->assertCount(2, $ids, 'anonymous sees only the 2 published rows');

        // Resolve back to titles so the assertion is on observable behaviour
        // (which rows survived) and not on raw IDs that depend on autoincrement.
        $entities = [];
        foreach ($this->repository->findMany($ids) as $entity) {
            $id = $entity->id();
            if ($id !== null) {
                $entities[$id] = $entity;
            }
        }
        $titles = [];
        foreach ($ids as $id) {
            $entity = $entities[$id] ?? null;
            if ($entity instanceof EntityInterface) {
                $title = $entity->get('title');
                $titles[] = is_string($title) ? $title : '';
            }
        }
        $this->assertSame(['p1', 'p2'], $titles);
    }

    #[Test]
    public function anonymousAccountIsAValidBoundPrincipal(): void
    {
        // Regression guard: binding AnonymousUser must NOT trip
        // MissingQueryAccountException. id=0 is a real account, not absence.
        $ids = $this->repository->getQuery()
            ->setAccount(new AnonymousUser())
            ->execute();

        // Even if the result set is empty, reaching this assertion proves no
        // exception was thrown.
        $this->assertIsArray($ids);
    }

    /**
     * Policy: published rows are viewable by anyone; draft rows are forbidden
     * for everyone except the (omitted) owner. Anonymous is never owner, so
     * draft rows are always Forbidden for AnonymousUser.
     */
    private function publishedOnlyForAnonymousPolicy(): AccessPolicyInterface
    {
        return new class implements AccessPolicyInterface {
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                $status = $entity->get('status');
                if ($status === 'published') {
                    return AccessResult::allowed('public read');
                }
                return AccessResult::forbidden('draft not visible to anonymous');
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
