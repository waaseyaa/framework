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
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;

/**
 * Pins the INTENTIONAL Layer-3 (entity-query) access semantics declared in
 * docs/specs/access-control.md (the §"Layer 3" filter contract): at the
 * query/count layer the survivor test is `!isForbidden()`, so a NEUTRAL access
 * result (no policy opined, or a policy declined without forbidding) ADMITS the
 * row. Only FORBIDDEN drops it.
 *
 * This is the open-by-default query layer. It is DELIBERATELY asymmetric with
 * the entity-handler/controller layer (isAllowed(), deny-by-default): a Neutral
 * row admitted here is re-filtered out downstream by every serializing consumer
 * (JsonApiController::index isAllowed() + accessFilteredTotal, GraphQlAccessGuard
 * ::canView, ai-vector SearchController, mcp tools), so admitting it at the query
 * layer leaks nothing while preserving the unfiltered-candidate-window cursor
 * contract — AND it keeps query-row visibility identical to single-entity load
 * visibility (consumers cannot construct a query returning rows they could not
 * load individually).
 *
 * Audit finding C-6 recommended flipping this survivor test to isAllowed()
 * (deny-by-default). That flip is UNSAFE: it would contradict the spec and would
 * HIDE legitimately-visible Neutral rows for consumers that read query results
 * directly without an isAllowed() re-filter (e.g. the genealogy pedigree/family
 * services). The pre-existing SqlEntityQueryAccessCheckTest only exercises
 * Allowed-vs-Forbidden, so the Neutral-admits-row branch was previously UNPINNED.
 * If anyone flips the survivor test to isAllowed(), these assertions fail loudly
 * and force a spec change (access-control.md) BEFORE the semantics can change.
 */
#[CoversClass(SqlEntityQuery::class)]
final class SqlEntityQueryNeutralAdmitsRowTest extends TestCase
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

        $dispatcher = new EventDispatcher();
        $this->storage = new SqlEntityStorage($entityType, $this->database, $dispatcher);
    }

    #[Test]
    public function neutralResultAdmitsRowAtQueryLayer(): void
    {
        $account = $this->makeAccount(1);
        $this->seedRows([
            ['title' => 'n1', 'owner_id' => 1],
            ['title' => 'n2', 'owner_id' => 2],
            ['title' => 'n3', 'owner_id' => 3],
        ]);

        // A policy that NEVER forbids and NEVER allows — every row resolves to
        // Neutral. Per the spec all three rows must survive the query layer
        // (open-by-default). Under a hypothetical isAllowed() flip this would
        // collapse to 0 survivors and fail.
        $handler = new EntityAccessHandler();
        $handler->addPolicy($this->alwaysNeutralPolicy());

        $ids = $this->newQuery()
            ->withAccessHandler($handler)
            ->withEntityLoader($this->storage->loadMultiple(...))
            ->setAccount($account)
            ->sort('id', 'ASC')
            ->execute();

        $this->assertCount(3, $ids, 'Neutral access results must admit rows at the query layer (access-control.md Layer 3).');
    }

    #[Test]
    public function neutralResultIsCountedAtQueryLayer(): void
    {
        $account = $this->makeAccount(1);
        $this->seedRows([
            ['title' => 'n1', 'owner_id' => 1],
            ['title' => 'n2', 'owner_id' => 2],
            ['title' => 'n3', 'owner_id' => 3],
        ]);

        $handler = new EntityAccessHandler();
        $handler->addPolicy($this->alwaysNeutralPolicy());

        $result = $this->newQuery()
            ->withAccessHandler($handler)
            ->withEntityLoader($this->storage->loadMultiple(...))
            ->setAccount($account)
            ->count()
            ->execute();

        // count() shares filterCandidates(); the Neutral survivor count is the
        // full cardinality. A flip to isAllowed() would return [0] here.
        $this->assertSame([3], $result, 'count() must reflect Neutral-admitted survivors (open-by-default query layer).');
    }

    #[Test]
    public function noPolicyAtAllAdmitsEveryRow(): void
    {
        // The empty-handler fallback returns Neutral for every entity. With a
        // real loader bound, all rows must survive — the pass-through must not
        // silently lock callers out.
        $account = $this->makeAccount(1);
        $this->seedRows([
            ['title' => 'x1', 'owner_id' => 1],
            ['title' => 'x2', 'owner_id' => 2],
        ]);

        $ids = $this->newQuery()
            ->withAccessHandler(new EntityAccessHandler())
            ->withEntityLoader($this->storage->loadMultiple(...))
            ->setAccount($account)
            ->execute();

        $this->assertCount(2, $ids, 'An empty (no-policy) handler returns Neutral and must admit every row.');
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

    /**
     * Policy that opines Neutral on every operation — never Allowed, never
     * Forbidden. Exercises the Neutral-admits-row branch the spec mandates.
     */
    private function alwaysNeutralPolicy(): AccessPolicyInterface
    {
        return new class implements AccessPolicyInterface {
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral('no opinion');
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
