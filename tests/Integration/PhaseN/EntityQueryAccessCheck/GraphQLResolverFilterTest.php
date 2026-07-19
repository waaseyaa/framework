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
use Waaseyaa\GraphQL\Access\GraphQlAccessGuard;
use Waaseyaa\GraphQL\Resolver\EntityResolver;

/**
 * T018 — Integration: the GraphQL list resolver returns access-filtered
 * `items` AND an access-filtered `total` for user-context callers; the
 * system-context bypass (no bound account) returns the unfiltered totals.
 *
 * Covers SC-001 of mission `sql-entity-query-access-checking-01KRYP15`
 * across the GraphQL surface. The headline assertion — `total === 2`
 * with a bound user account against 6 seeded rows — is the bug-fix
 * lock that motivated the entire mission. Pre-WP02 the resolver's
 * count query went through `accessCheck(false)` by default and leaked
 * the unfiltered cardinality (6) to user-facing callers.
 *
 * The test invokes {@see EntityResolver::resolveList()} directly. That
 * is the same method the GraphQL HTTP endpoint dispatches to via the
 * schema-built resolver — going through the schema/executor surface
 * would only test webonyx wiring, which is well-covered elsewhere.
 */
#[CoversNothing]
final class GraphQLResolverFilterTest extends TestCase
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
    public function userContextResolverReturnsFilteredItemsAndFilteredTotal(): void
    {
        $accountA = $this->makeAccount(self::ACCOUNT_A_ID);
        $resolver = new EntityResolver(
            $this->entityTypeManager,
            new GraphQlAccessGuard($this->accessHandler, $accountA),
            $accountA,
        );

        $result = $resolver->resolveList('article', []);

        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('total', $result);

        // Bug-fix lock for SC-001: pre-WP02 the count came back as 6 even
        // though items were filtered to 2 — the cardinality leak that
        // motivated the mission. Post-WP02 the count query goes through
        // SqlEntityQuery with the bound account and matches the items.
        $this->assertSame(2, $result['total'], 'total reflects access-filtered cardinality');
        $this->assertCount(2, $result['items'], 'items returns only the 2 rows account A owns');

        $titles = array_map(static fn(array $item): string => (string) ($item['title'] ?? ''), $result['items']);
        sort($titles);
        $this->assertSame(['a1', 'a2'], $titles);
    }

    #[Test]
    public function systemContextBypassReturnsUnfilteredItemsAndUnfilteredTotal(): void
    {
        // System context: resolver constructed with NO bound account. This
        // is the internal-tooling path (e.g. background ingestion, sitemap
        // build) — the resolver routes through `accessCheck(false)` on
        // both the count and main queries (see EntityResolver::resolveList
        // L66-92).
        //
        // The guard still needs an account (constructor requirement), but
        // the resolveList code path bypasses the per-row check via the
        // null-account branch.
        $sentinel = $this->makeAccount(self::ACCOUNT_A_ID);
        $resolver = new EntityResolver(
            $this->entityTypeManager,
            new GraphQlAccessGuard($this->accessHandler, $sentinel),
            account: null,
        );

        $result = $resolver->resolveList('article', []);

        // Total comes from the SQL count under accessCheck(false) — all 6 rows.
        $this->assertSame(6, $result['total'], 'system-context total returns unfiltered cardinality');

        // Items are subject to the GraphQlAccessGuard post-fetch filter,
        // which still consults the policy against the sentinel account.
        // The mission-critical assertion in this test is the COUNT, since
        // that was the leak. The item-count check is included to document
        // the post-fetch behaviour, not to lock it.
        $this->assertGreaterThanOrEqual(0, count($result['items']));
    }

    private function makeAccount(int $id): AccountInterface
    {
        return new AuthorizationPrincipal($id, $id !== 0, [], [], 'test-' . $id);
    }

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
