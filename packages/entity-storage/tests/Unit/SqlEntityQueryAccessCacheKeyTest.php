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
use Waaseyaa\EntityStorage\SqlEntityQuery;
use Waaseyaa\EntityStorage\SqlEntityQueryResultCache;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;

/**
 * Regression test for C-010: the query result cache key must include the
 * access dimension (accessCheckEnabled + a per-account discriminator) so an
 * access-filtered list for one account cannot be served from a cache entry
 * populated by a different account or by an unfiltered accessCheck(false) run.
 *
 * The storage owns a single SqlEntityQueryResultCache that every getQuery()
 * shares, so identical conditions across queries collide on the fingerprint
 * unless the fingerprint discriminates on access + account.
 */
#[CoversClass(SqlEntityQuery::class)]
#[CoversClass(SqlEntityQueryResultCache::class)]
final class SqlEntityQueryAccessCacheKeyTest extends TestCase
{
    private DBALDatabase $database;

    private SqlEntityStorage $storage;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $entityType = new EntityType(
            id: 'article',
            label: 'Article',
            class: TestStorageEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
        );

        $schemaHandler = new SqlSchemaHandler($entityType, $this->database);
        $schemaHandler->ensureTable();

        // Single shared result cache across every getQuery() call — the leak
        // surface. (SqlEntityStorage defaults to one anyway; pass it explicitly
        // to make the shared-key contract unambiguous.)
        $this->storage = new SqlEntityStorage(
            $entityType,
            $this->database,
            new EventDispatcher(),
            queryResultCache: new SqlEntityQueryResultCache(),
        );
    }

    #[Test]
    public function differentAccountsDoNotShareAnAccessFilteredCacheEntry(): void
    {
        $this->seedRows([
            ['title' => 'a1', 'owner_id' => 1],
            ['title' => 'a2', 'owner_id' => 1],
            ['title' => 'b1', 'owner_id' => 2],
            ['title' => 'b2', 'owner_id' => 2],
        ]);

        $handler = new EntityAccessHandler();
        $handler->addPolicy($this->ownerOnlyPolicy());

        // Account 1 runs first and populates the shared cache with ITS
        // access-filtered survivors (a1, a2).
        $idsA = $this->newQuery()
            ->withAccessHandler($handler)
            ->withEntityLoader($this->storage->loadMultiple(...))
            ->setAccount($this->makeAccount(1))
            ->sort('id', 'ASC')
            ->execute();
        $this->assertSame(['a1', 'a2'], $this->titlesFor($idsA));

        // Account 2 runs the identical query. Pre-fix it gets a cache hit on
        // account 1's fingerprint and is served a1/a2 — a cross-account leak.
        // Post-fix it computes its own survivors (b1, b2).
        $idsB = $this->newQuery()
            ->withAccessHandler($handler)
            ->withEntityLoader($this->storage->loadMultiple(...))
            ->setAccount($this->makeAccount(2))
            ->sort('id', 'ASC')
            ->execute();

        $this->assertSame(
            ['b1', 'b2'],
            $this->titlesFor($idsB),
            'account 2 must see only its own rows, not account 1\'s cached survivors',
        );
    }

    #[Test]
    public function accessCheckFalseResultIsNotServedToAnAccessCheckedQuery(): void
    {
        $this->seedRows([
            ['title' => 'a1', 'owner_id' => 1],
            ['title' => 'a2', 'owner_id' => 1],
            ['title' => 'b1', 'owner_id' => 2],
            ['title' => 'b2', 'owner_id' => 2],
        ]);

        // System context: unfiltered bypass populates the shared cache with all
        // four candidate ids under the (conditions-only, pre-fix) fingerprint.
        $unfiltered = $this->newQuery()
            ->accessCheck(false)
            ->sort('id', 'ASC')
            ->execute();
        $this->assertCount(4, $unfiltered);

        // Same conditions, now WITH access checking for account 1. Pre-fix it
        // gets a cache hit on the bypass entry and is handed all four ids — a
        // filter bypass. Post-fix it is filtered to account 1's two rows.
        $handler = new EntityAccessHandler();
        $handler->addPolicy($this->ownerOnlyPolicy());

        $checked = $this->newQuery()
            ->withAccessHandler($handler)
            ->withEntityLoader($this->storage->loadMultiple(...))
            ->setAccount($this->makeAccount(1))
            ->sort('id', 'ASC')
            ->execute();

        $this->assertSame(
            ['a1', 'a2'],
            $this->titlesFor($checked),
            'an access-checked query must not be served the accessCheck(false) unfiltered list',
        );
    }

    #[Test]
    public function sameAccountStillSharesItsCacheEntry(): void
    {
        $this->seedRows([
            ['title' => 'a1', 'owner_id' => 1],
            ['title' => 'b1', 'owner_id' => 2],
        ]);

        $handler = new EntityAccessHandler();
        $handler->addPolicy($this->ownerOnlyPolicy());

        $account = $this->makeAccount(1);

        $first = $this->newQuery()
            ->withAccessHandler($handler)
            ->withEntityLoader($this->storage->loadMultiple(...))
            ->setAccount($account)
            ->sort('id', 'ASC')
            ->execute();
        $this->assertSame(['a1'], $this->titlesFor($first));

        // Insert a now-visible row WITHOUT going through storage->save() (which
        // would invalidate the cache). owner_id lives in the _data blob (it is
        // not a base column), so the hydrated entity's owner matches account 1.
        // A cache hit means the second run for the same account returns the
        // memoized survivor set (no a2), proving per-account caching still works.
        $this->database->insert('article')
            ->fields(['uuid', 'title', 'langcode', '_data'])
            ->values(['uuid-a2', 'a2', 'en', json_encode(['owner_id' => 1], JSON_THROW_ON_ERROR)])
            ->execute();

        $second = $this->newQuery()
            ->withAccessHandler($handler)
            ->withEntityLoader($this->storage->loadMultiple(...))
            ->setAccount($this->makeAccount(1))
            ->sort('id', 'ASC')
            ->execute();

        $this->assertSame(
            ['a1'],
            $this->titlesFor($second),
            'a second identical query for the same account hits the cache (no a2)',
        );
    }

    private function newQuery(): SqlEntityQuery
    {
        $query = $this->storage->getQuery();
        \assert($query instanceof SqlEntityQuery);
        return $query;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function seedRows(array $rows): void
    {
        foreach ($rows as $row) {
            $this->storage->save($this->storage->create($row));
        }
    }

    /**
     * @param array<int, int|string> $ids
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

    private function makeAccount(int $id): AccountInterface
    {
        return new class($id) implements AccountInterface {
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
                if (\is_int($ownerId) && $ownerId === $account->id()) {
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
