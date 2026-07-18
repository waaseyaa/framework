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

/**
 * T021 — Integration: capability-driven bypass is a policy-level outcome,
 * not a query-level toggle.
 *
 * Covers SC-002 (admin bypass via capability) of mission
 * `sql-entity-query-access-checking-01KRYP15`.
 *
 * Seeds 6 rows owned by three different accounts. Policy: `allowed` when
 * the account owns the row OR holds the `entity.bypass_ownership`
 * permission; `forbidden` otherwise. An admin account (id=99) holds the
 * permission. The test binds the admin via {@see setAccount()} and runs
 * the SAME query path as everyone else — **no** call to
 * `accessCheck(false)`. The admin sees all 6 rows because the POLICY
 * grants it, not because the check was suppressed.
 *
 * The negative invariant matters: if WP02 had wired bypass via
 * `accessCheck(false)` at the consumer level for admins, this test would
 * still pass — but it would mean the wrong thing. By asserting on a
 * capability-only bypass with `accessCheck(true)` left at its default,
 * we lock the policy → query contract.
 */
#[CoversNothing]
final class AdminBypassCapabilityTest extends TestCase
{
    public const BYPASS_PERMISSION = 'entity.bypass_ownership';

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
            accessHandler: new EntityAccessHandler([$this->ownerOrBypassPolicy()]),
        );

        foreach ([
            ['title' => 'a1', 'owner_id' => 1],
            ['title' => 'a2', 'owner_id' => 1],
            ['title' => 'b1', 'owner_id' => 2],
            ['title' => 'b2', 'owner_id' => 2],
            ['title' => 'c1', 'owner_id' => 3],
            ['title' => 'c2', 'owner_id' => 3],
        ] as $row) {
            $this->repository->save($this->repository->create($row), validate: false);
        }
    }

    #[Test]
    public function adminWithBypassCapabilitySeesAllRows(): void
    {
        $admin = $this->makeAdmin();

        // Note: accessCheck(true) is the default — left untouched on purpose.
        // The admin sees everything because the policy said so, not because
        // the check was silenced.
        $ids = $this->repository->getQuery()
            ->setAccount($admin)
            ->sort('id', 'ASC')
            ->execute();

        $this->assertCount(6, $ids, 'admin with bypass permission sees the full set');
    }

    #[Test]
    public function nonAdminWithoutBypassSeesOnlyOwnedRows(): void
    {
        // Negative control: prove the policy is doing real work by binding a
        // plain account (id=1) without bypass. They see only rows they own
        // (a1, a2 — 2 rows).
        $plain = $this->makeAccount(1, []);

        $ids = $this->repository->getQuery()
            ->setAccount($plain)
            ->sort('id', 'ASC')
            ->execute();

        $this->assertCount(2, $ids, 'plain account sees only their own 2 rows');
    }

    private function makeAdmin(): AccountInterface
    {
        return $this->makeAccount(99, [self::BYPASS_PERMISSION]);
    }

    /**
     * @param list<string> $permissions
     */
    private function makeAccount(int $id, array $permissions): AccountInterface
    {
        return new class ($id, $permissions) implements AccountInterface {
            /** @param list<string> $permissions */
            public function __construct(
                private readonly int $accountId,
                private readonly array $permissions,
            ) {}

            public function id(): int|string
            {
                return $this->accountId;
            }

            public function hasPermission(string $permission): bool
            {
                return in_array($permission, $this->permissions, true);
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

    /**
     * Policy: Allowed when the account owns the row OR holds
     * `entity.bypass_ownership`. Otherwise Forbidden.
     */
    private function ownerOrBypassPolicy(): AccessPolicyInterface
    {
        return new class implements AccessPolicyInterface {
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                if ($account->hasPermission(AdminBypassCapabilityTest::BYPASS_PERMISSION)) {
                    return AccessResult::allowed('bypass capability');
                }

                $ownerId = $entity->get('owner_id');
                if (is_int($ownerId) && $ownerId === $account->id()) {
                    return AccessResult::allowed('owner match');
                }

                return AccessResult::forbidden('not owner, no bypass');
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
