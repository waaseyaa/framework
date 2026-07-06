<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AI\Vector\EntityEmbedder;
use Waaseyaa\AI\Vector\InMemoryVectorStore;
use Waaseyaa\AI\Vector\SimilarityResult;
use Waaseyaa\AI\Vector\Testing\FakeEmbeddingProvider;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;

final class EntityEmbedderTest extends TestCase
{
    private FakeEmbeddingProvider $provider;
    private InMemoryVectorStore $store;
    private EntityEmbedderTestEntityTypeManager $entityTypeManager;
    private EntityAccessHandler $allowAllAccessHandler;
    private EntityEmbedder $embedder;
    private AccountInterface $account;

    protected function setUp(): void
    {
        $this->provider = new FakeEmbeddingProvider(dimensions: 32);
        $this->store = new InMemoryVectorStore();
        $this->entityTypeManager = new EntityEmbedderTestEntityTypeManager();
        $this->allowAllAccessHandler = new EntityAccessHandler([new EntityEmbedderTestAllowAllPolicy()]);
        $this->account = new EntityEmbedderTestAccount();
        $this->embedder = new EntityEmbedder(
            $this->provider,
            $this->store,
            $this->allowAllAccessHandler,
            $this->entityTypeManager,
        );
    }

    public function testEmbedEntityGeneratesAndStoresEmbedding(): void
    {
        $entity = $this->createMockEntity('node', 1, 'Test Article');

        $result = $this->embedder->embedEntity($entity);

        $this->assertSame('node', $result->entityTypeId);
        $this->assertSame(1, $result->entityId);
        $this->assertCount(32, $result->vector);
        $this->assertSame('Test Article', $result->metadata['label']);
        $this->assertSame('article', $result->metadata['bundle']);
        $this->assertGreaterThan(0, $result->createdAt);

        // Verify it's stored.
        $this->assertTrue($this->store->has('node', 1));
    }

    public function testSearchSimilarFindsRelatedEntities(): void
    {
        // Embed two entities.
        $entity1 = $this->createMockEntity('node', 1, 'PHP Programming Guide');
        $entity2 = $this->createMockEntity('node', 2, 'Cooking Recipes');

        $this->embedder->embedEntity($entity1);
        $this->embedder->embedEntity($entity2);

        // Search should return all embedded entities.
        $results = $this->embedder->searchSimilar('PHP Programming Guide', $this->account);

        $this->assertCount(2, $results);

        // Results should be sorted by score descending.
        $this->assertGreaterThanOrEqual($results[1]->score, $results[0]->score);

        // Each result should have an embedding with the correct entity type.
        foreach ($results as $result) {
            $this->assertSame('node', $result->embedding->entityTypeId);
            $this->assertInstanceOf(SimilarityResult::class, $result);
        }
    }

    public function testSearchSimilarWithEntityTypeFilter(): void
    {
        $node = $this->createMockEntity('node', 1, 'Test Node');
        $term = $this->createMockEntity('taxonomy_term', 2, 'Test Term', 'tags');

        $this->embedder->embedEntity($node);
        $this->embedder->embedEntity($term);

        $results = $this->embedder->searchSimilar('test', $this->account, entityTypeId: 'node');

        $this->assertCount(1, $results);
        $this->assertSame('node', $results[0]->embedding->entityTypeId);
    }

    public function testSearchSimilarWithLimit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $entity = $this->createMockEntity('node', $i, "Article $i");
            $this->embedder->embedEntity($entity);
        }

        $results = $this->embedder->searchSimilar('article', $this->account, limit: 3);

        $this->assertCount(3, $results);
    }

    public function testRemoveEntity(): void
    {
        $entity = $this->createMockEntity('node', 1, 'Test');
        $this->embedder->embedEntity($entity);

        $this->assertTrue($this->store->has('node', 1));

        $this->embedder->removeEntity('node', 1);

        $this->assertFalse($this->store->has('node', 1));
    }

    /**
     * Exploit test (R-gate regression lock): before the access-filter gate,
     * `searchSimilar()` returned every stored embedding regardless of the
     * caller's access. Seed two entities, forbid view on one via a real
     * `EntityAccessHandler` policy, and assert only the permitted entity's
     * result comes back.
     */
    public function testSearchSimilarDropsResultsTheAccountCannotView(): void
    {
        $permitted = $this->createMockEntity('node', 1, 'Permitted Article');
        $forbidden = $this->createMockEntity('node', 2, 'Forbidden Article');

        $this->embedder->embedEntity($permitted);
        $this->embedder->embedEntity($forbidden);

        $gatedAccessHandler = new EntityAccessHandler([
            new EntityEmbedderTestSelectiveForbidPolicy(forbiddenEntityId: 2),
        ]);
        $gatedEmbedder = new EntityEmbedder(
            $this->provider,
            $this->store,
            $gatedAccessHandler,
            $this->entityTypeManager,
        );

        $results = $gatedEmbedder->searchSimilar('Article', $this->account, limit: 10, entityTypeId: 'node');

        $this->assertCount(1, $results);
        $this->assertSame(1, $results[0]->embedding->entityId);
    }

    private function createMockEntity(
        string $entityTypeId,
        int|string $id,
        string $label,
        string $bundle = 'article',
    ): EntityInterface {
        $entity = $this->createMock(EntityInterface::class);
        $entity->method('getEntityTypeId')->willReturn($entityTypeId);
        $entity->method('id')->willReturn($id);
        $entity->method('label')->willReturn($label);
        $entity->method('bundle')->willReturn($bundle);
        $values = [
            'id' => $id,
            'type' => $entityTypeId,
            'label' => $label,
            'bundle' => $bundle,
        ];
        $entity->method('toArray')->willReturn($values);
        $entity->method('get')->willReturnCallback(
            static fn(string $name): mixed => $values[$name] ?? null,
        );

        $this->entityTypeManager->register($entity);

        return $entity;
    }
}

/**
 * Local test doubles for the `EntityEmbedder` access-filter gate. Each
 * `Waaseyaa\Access\AccessPolicyInterface`/`EntityTypeManagerInterface`
 * implementation here is intentionally minimal (only what `searchSimilar()`
 * exercises: `hasDefinition()`, `getRepository()->find()`), mirroring the
 * local-fake pattern used elsewhere for provider tests (no shared
 * autoload-dev fixture is wired for ai-vector).
 */
final class EntityEmbedderTestEntityTypeManager implements EntityTypeManagerInterface
{
    /** @var array<string, array<string, EntityInterface>> */
    private array $entitiesByType = [];

    public function register(EntityInterface $entity): void
    {
        $entityTypeId = $entity->getEntityTypeId();
        $this->entitiesByType[$entityTypeId] ??= [];
        $this->entitiesByType[$entityTypeId][(string) $entity->id()] = $entity;
    }

    public function hasDefinition(string $entityTypeId): bool
    {
        return isset($this->entitiesByType[$entityTypeId]);
    }

    public function getRepository(string $entityTypeId): EntityRepositoryInterface
    {
        return new EntityEmbedderTestRepository($this->entitiesByType[$entityTypeId] ?? []);
    }

    public function getDefinition(string $entityTypeId): \Waaseyaa\Entity\EntityTypeInterface
    {
        throw new \LogicException('Not needed by EntityEmbedder.');
    }

    public function resolveFieldDefinitions(string $entityTypeId, ?string $bundle = null): array
    {
        throw new \LogicException('Not needed by EntityEmbedder.');
    }

    public function registerEntityType(\Waaseyaa\Entity\EntityTypeInterface $type, ?string $registrant = null): void
    {
        throw new \LogicException('Not needed by EntityEmbedder.');
    }

    public function registerCoreEntityType(\Waaseyaa\Entity\EntityTypeInterface $type, ?string $registrant = null): void
    {
        throw new \LogicException('Not needed by EntityEmbedder.');
    }

    public function getDefinitions(): array
    {
        throw new \LogicException('Not needed by EntityEmbedder.');
    }

    public function getStorage(string $entityTypeId): \Waaseyaa\Entity\Storage\EntityStorageInterface
    {
        throw new \LogicException('Not needed by EntityEmbedder.');
    }
}

final class EntityEmbedderTestRepository implements EntityRepositoryInterface
{
    /**
     * @param array<string, EntityInterface> $entities
     */
    public function __construct(private readonly array $entities) {}

    public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface
    {
        return $this->entities[$id] ?? null;
    }

    public function findMany(array $ids, ?string $langcode = null, bool $fallback = false): array
    {
        $found = [];
        foreach ($ids as $id) {
            if (isset($this->entities[(string) $id])) {
                $found[] = $this->entities[(string) $id];
            }
        }

        return $found;
    }

    public function create(array $values = []): EntityInterface
    {
        throw new \LogicException('Not needed by EntityEmbedder.');
    }

    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array
    {
        throw new \LogicException('Not needed by EntityEmbedder.');
    }

    public function getQuery(): EntityQueryInterface
    {
        throw new \LogicException('Not needed by EntityEmbedder.');
    }

    public function save(EntityInterface $entity, bool $validate = true): int
    {
        throw new \LogicException('Not needed by EntityEmbedder.');
    }

    public function delete(EntityInterface $entity): void
    {
        throw new \LogicException('Not needed by EntityEmbedder.');
    }

    public function exists(string $id): bool
    {
        return isset($this->entities[$id]);
    }

    public function count(array $criteria = []): int
    {
        throw new \LogicException('Not needed by EntityEmbedder.');
    }

    public function loadRevision(string $entityId, int $revisionId): ?EntityInterface
    {
        throw new \LogicException('Not needed by EntityEmbedder.');
    }

    public function rollback(string $entityId, int $targetRevisionId): EntityInterface
    {
        throw new \LogicException('Not needed by EntityEmbedder.');
    }

    public function listRevisions(string $entityId): array
    {
        throw new \LogicException('Not needed by EntityEmbedder.');
    }

    public function setCurrentRevision(string $entityId, int $revisionId): EntityInterface
    {
        throw new \LogicException('Not needed by EntityEmbedder.');
    }

    public function loadPublishedRevision(string $entityId): ?EntityInterface
    {
        throw new \LogicException('Not needed by EntityEmbedder.');
    }

    public function setPublishedRevision(string $entityId, int $revisionId): EntityInterface
    {
        throw new \LogicException('Not needed by EntityEmbedder.');
    }

    public function saveMany(array $entities, bool $validate = true): array
    {
        throw new \LogicException('Not needed by EntityEmbedder.');
    }

    public function deleteMany(array $entities): int
    {
        throw new \LogicException('Not needed by EntityEmbedder.');
    }

    public function findTranslations(EntityInterface $entity): array
    {
        throw new \LogicException('Not needed by EntityEmbedder.');
    }

    public function saveTranslation(string $entityId, string $langcode, array $values, ?string $log = null): int
    {
        throw new \LogicException('Not needed by EntityEmbedder.');
    }

    public function loadTranslation(string $entityId, string $langcode): ?EntityInterface
    {
        throw new \LogicException('Not needed by EntityEmbedder.');
    }

    public function listTranslationRevisions(string $entityId, string $langcode): array
    {
        throw new \LogicException('Not needed by EntityEmbedder.');
    }
}

final class EntityEmbedderTestAccount implements AccountInterface
{
    public function id(): int|string
    {
        return 42;
    }

    public function hasPermission(string $permission): bool
    {
        return true;
    }

    public function getRoles(): array
    {
        return ['authenticated'];
    }

    public function isAuthenticated(): bool
    {
        return true;
    }
}

final class EntityEmbedderTestAllowAllPolicy implements AccessPolicyInterface
{
    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        return AccessResult::allowed('test: allow all');
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::allowed('test: allow all');
    }

    public function appliesTo(string $entityTypeId): bool
    {
        return true;
    }
}

final class EntityEmbedderTestSelectiveForbidPolicy implements AccessPolicyInterface
{
    public function __construct(private readonly int|string $forbiddenEntityId) {}

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if ((string) $entity->id() === (string) $this->forbiddenEntityId) {
            return AccessResult::forbidden('test: selectively forbidden');
        }

        return AccessResult::allowed('test: everything else permitted');
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::allowed('test: allow all');
    }

    public function appliesTo(string $entityTypeId): bool
    {
        return true;
    }
}
