<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
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
use Waaseyaa\EntityStorage\EntityStorageFactory;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;

/**
 * The access filter is only as good as the wiring: storage built by the factory
 * (and the kernel, which uses the same {@see SqlEntityStorage} resolver seam)
 * must thread the real access handler so a plain `getQuery()` — no manual
 * `withAccessHandler()` — is fail-closed (issue #1714). Before the fix, the
 * factory built storage with no handler, so `getQuery()` resolved an EMPTY
 * handler and every non-Forbidden row passed.
 */
#[CoversClass(EntityStorageFactory::class)]
final class EntityStorageFactoryAccessWiringTest extends TestCase
{
    #[Test]
    public function factory_wired_storage_getQuery_filters_forbidden_rows(): void
    {
        [$db, $entityType] = $this->schema();
        $handler = new EntityAccessHandler();
        $handler->addPolicy($this->ownerOnlyPolicy());

        $factory = new EntityStorageFactory(
            $db,
            new EventDispatcher(),
            accessHandlerResolver: fn(): EntityAccessHandler => $handler,
        );
        $storage = $factory->getStorage($entityType);
        $this->seed($storage, [['title' => 'mine', 'owner_id' => 1], ['title' => 'theirs', 'owner_id' => 2]]);

        // NOTE: no manual ->withAccessHandler(); the factory must have threaded it.
        $ids = $storage->getQuery()->setAccount($this->account(1))->execute();

        self::assertSame(['mine'], $this->titles($storage, $ids), 'getQuery() must drop the row the account cannot view');
    }

    #[Test]
    public function unwired_factory_storage_is_fail_open_documenting_the_pre_fix_gap(): void
    {
        [$db, $entityType] = $this->schema();
        $factory = new EntityStorageFactory($db, new EventDispatcher()); // no resolver = pre-fix wiring
        $storage = $factory->getStorage($entityType);
        $this->seed($storage, [['title' => 'mine', 'owner_id' => 1], ['title' => 'theirs', 'owner_id' => 2]]);

        $ids = $storage->getQuery()->setAccount($this->account(1))->execute();

        self::assertCount(2, $ids, 'with no handler wired, the empty handler lets every row pass (the bug)');
    }

    /** @return array{0: DBALDatabase, 1: EntityType} */
    private function schema(): array
    {
        $db = DBALDatabase::createSqlite();
        $entityType = new EntityType(
            id: 'article',
            label: 'Article',
            class: TestStorageEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
        );
        new SqlSchemaHandler($entityType, $db)->ensureTable();

        return [$db, $entityType];
    }

    /** @param list<array<string, mixed>> $rows */
    private function seed(SqlEntityStorage $storage, array $rows): void
    {
        foreach ($rows as $row) {
            $storage->save($storage->create($row));
        }
    }

    /**
     * @param array<int, int|string> $ids
     * @return list<string>
     */
    private function titles(SqlEntityStorage $storage, array $ids): array
    {
        $entities = $storage->loadMultiple($ids);
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

    private function account(int $id): AccountInterface
    {
        return new class ($id) implements AccountInterface {
            public function __construct(private readonly int $accountId) {}

            public function id(): int|string
            {
                return $this->accountId;
            }

            public function hasPermission(string $permission): bool
            {
                return false;
            }

            public function getRoles(): array
            {
                return [];
            }

            public function isAuthenticated(): bool
            {
                return $this->accountId !== 0;
            }
        };
    }

    private function ownerOnlyPolicy(): AccessPolicyInterface
    {
        return new class implements AccessPolicyInterface {
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                $ownerId = $entity->get('owner_id');

                return is_int($ownerId) && $ownerId === $account->id()
                    ? AccessResult::allowed('owner match')
                    : AccessResult::forbidden('owner mismatch');
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
