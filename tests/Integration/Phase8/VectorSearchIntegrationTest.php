<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase8;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AI\Vector\EntityEmbedder;
use Waaseyaa\AI\Vector\InMemoryVectorStore;
use Waaseyaa\AI\Vector\Testing\FakeEmbeddingProvider;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Node\Node;
use Waaseyaa\Taxonomy\Term;

/**
 * Entity embedding and similarity search with real entities.
 *
 * Exercises: waaseyaa/ai-vector (EntityEmbedder, InMemoryVectorStore,
 * FakeEmbeddingProvider) with waaseyaa/node (Node) and waaseyaa/taxonomy (Term).
 */
#[CoversNothing]
final class VectorSearchIntegrationTest extends TestCase
{
    private FakeEmbeddingProvider $embeddingProvider;
    private InMemoryVectorStore $vectorStore;
    private VectorSearchIntegrationTestEntityTypeManager $entityTypeManager;
    private EntityAccessHandler $allowAllAccessHandler;
    private EntityEmbedder $embedder;
    private AccountInterface $account;

    protected function setUp(): void
    {
        $this->embeddingProvider = new FakeEmbeddingProvider(128);
        $this->vectorStore = new InMemoryVectorStore();
        $this->entityTypeManager = new VectorSearchIntegrationTestEntityTypeManager();
        $this->allowAllAccessHandler = new EntityAccessHandler([new VectorSearchIntegrationTestAllowAllPolicy()]);
        $this->account = new VectorSearchIntegrationTestAccount();
        $this->embedder = new EntityEmbedder(
            $this->embeddingProvider,
            $this->vectorStore,
            $this->allowAllAccessHandler,
            $this->entityTypeManager,
        );
    }

    /**
     * Embeds an entity and registers it with the fake entity type manager so
     * `EntityEmbedder::searchSimilar()`'s access-filter gate can load it back
     * (the gate looks up each candidate through
     * `EntityTypeManagerInterface::getRepository()->find()`).
     */
    private function embed(EntityInterface $entity): \Waaseyaa\AI\Vector\EntityEmbedding
    {
        $this->entityTypeManager->register($entity);

        return $this->embedder->embedEntity($entity);
    }

    #[Test]
    public function embedMultipleNodesAndSearchForSimilarContent(): void
    {
        $phpNode = new Node([
            'nid' => 1,
            'type' => 'article',
            'title' => 'Introduction to PHP Programming',
            'uid' => 1,
        ]);
        $jsNode = new Node([
            'nid' => 2,
            'type' => 'article',
            'title' => 'JavaScript Framework Comparison',
            'uid' => 1,
        ]);
        $phpAdvNode = new Node([
            'nid' => 3,
            'type' => 'article',
            'title' => 'Advanced PHP Design Patterns',
            'uid' => 1,
        ]);

        $this->embed($phpNode);
        $this->embed($jsNode);
        $this->embed($phpAdvNode);

        // Search for PHP-related content.
        $results = $this->embedder->searchSimilar('PHP programming techniques', $this->account, 3);

        $this->assertCount(3, $results);
        // All results should have scores between 0 and 1.
        foreach ($results as $result) {
            $this->assertGreaterThan(-1.1, $result->score);
            $this->assertLessThanOrEqual(1.0, $result->score);
        }
    }

    #[Test]
    public function embeddedEntityIsStoredAndRetrievable(): void
    {
        $node = new Node([
            'nid' => 10,
            'type' => 'page',
            'title' => 'Stored Embedding Test',
            'uid' => 1,
        ]);

        $embedding = $this->embed($node);

        $this->assertSame('node', $embedding->entityTypeId);
        $this->assertSame(10, $embedding->entityId);
        $this->assertCount(128, $embedding->vector);
        $this->assertSame('Stored Embedding Test', $embedding->metadata['label']);
        $this->assertSame('page', $embedding->metadata['bundle']);
        $this->assertGreaterThan(0, $embedding->createdAt);

        // Retrieve from store.
        $this->assertTrue($this->vectorStore->has('node', 10));
        $stored = $this->vectorStore->get('node', 10);
        $this->assertNotNull($stored);
        $this->assertSame($embedding->vector, $stored->vector);
    }

    #[Test]
    public function searchWithEntityTypeFilter(): void
    {
        $node1 = new Node([
            'nid' => 1, 'type' => 'article', 'title' => 'PHP Article', 'uid' => 1,
        ]);
        $node2 = new Node([
            'nid' => 2, 'type' => 'article', 'title' => 'JS Article', 'uid' => 1,
        ]);
        $term = new Term([
            'tid' => 1, 'vid' => 'tags', 'name' => 'PHP Tag',
        ]);

        $this->embed($node1);
        $this->embed($node2);
        $this->embed($term);

        // Search without filter: returns all.
        $allResults = $this->embedder->searchSimilar('PHP', $this->account, 10);
        $this->assertCount(3, $allResults);

        // Search with node filter: only nodes.
        $nodeResults = $this->embedder->searchSimilar('PHP', $this->account, 10, 'node');
        $this->assertCount(2, $nodeResults);
        foreach ($nodeResults as $result) {
            $this->assertSame('node', $result->embedding->entityTypeId);
        }

        // Search with taxonomy_term filter.
        $termResults = $this->embedder->searchSimilar('PHP', $this->account, 10, 'taxonomy_term');
        $this->assertCount(1, $termResults);
        $this->assertSame('taxonomy_term', $termResults[0]->embedding->entityTypeId);
    }

    #[Test]
    public function removeEntityEmbeddingExcludesFromSearch(): void
    {
        $node1 = new Node([
            'nid' => 1, 'type' => 'article', 'title' => 'Keep This', 'uid' => 1,
        ]);
        $node2 = new Node([
            'nid' => 2, 'type' => 'article', 'title' => 'Remove This', 'uid' => 1,
        ]);

        $this->embed($node1);
        $this->embed($node2);

        $this->assertTrue($this->vectorStore->has('node', 2));

        // Remove node 2's embedding.
        $this->embedder->removeEntity('node', 2);

        $this->assertFalse($this->vectorStore->has('node', 2));
        $this->assertNull($this->vectorStore->get('node', 2));

        // Search should only return node 1.
        $results = $this->embedder->searchSimilar('article', $this->account, 10, 'node');
        $this->assertCount(1, $results);
        $this->assertSame(1, $results[0]->embedding->entityId);
    }

    #[Test]
    public function fakeEmbeddingProviderIsDeterministic(): void
    {
        $text = 'The quick brown fox jumps over the lazy dog';

        $vector1 = $this->embeddingProvider->embed($text);
        $vector2 = $this->embeddingProvider->embed($text);

        $this->assertSame($vector1, $vector2);
        $this->assertCount(128, $vector1);

        // Different text produces different vector.
        $vector3 = $this->embeddingProvider->embed('A completely different sentence');
        $this->assertNotSame($vector1, $vector3);
    }

    #[Test]
    public function fakeEmbeddingProviderBatchEmbedding(): void
    {
        $texts = ['Hello world', 'Goodbye world', 'Hello world'];

        $vectors = $this->embeddingProvider->embedBatch($texts);

        $this->assertCount(3, $vectors);
        // Same text produces same vector.
        $this->assertSame($vectors[0], $vectors[2]);
        // Different text produces different vector.
        $this->assertNotSame($vectors[0], $vectors[1]);
    }

    #[Test]
    public function embedDifferentEntityTypesAndFilterByType(): void
    {
        // Create nodes.
        for ($i = 1; $i <= 3; $i++) {
            $node = new Node([
                'nid' => $i,
                'type' => 'article',
                'title' => "Article {$i} about Programming",
                'uid' => 1,
            ]);
            $this->embed($node);
        }

        // Create terms.
        for ($i = 1; $i <= 2; $i++) {
            $term = new Term([
                'tid' => $i,
                'vid' => 'tags',
                'name' => "Tag {$i} Programming",
            ]);
            $this->embed($term);
        }

        // Search all: 5 results.
        $allResults = $this->embedder->searchSimilar('Programming', $this->account, 10);
        $this->assertCount(5, $allResults);

        // Search only nodes: 3 results.
        $nodeResults = $this->embedder->searchSimilar('Programming', $this->account, 10, 'node');
        $this->assertCount(3, $nodeResults);

        // Search only terms: 2 results.
        $termResults = $this->embedder->searchSimilar('Programming', $this->account, 10, 'taxonomy_term');
        $this->assertCount(2, $termResults);
    }

    #[Test]
    public function searchLimitRespectsMaxResults(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $node = new Node([
                'nid' => $i,
                'type' => 'article',
                'title' => "Node {$i}",
                'uid' => 1,
            ]);
            $this->embed($node);
        }

        $results = $this->embedder->searchSimilar('Node', $this->account, 2);
        $this->assertCount(2, $results);

        $results = $this->embedder->searchSimilar('Node', $this->account, 10);
        $this->assertCount(5, $results);
    }

    #[Test]
    public function reEmbeddingOverwritesPreviousVector(): void
    {
        $node = new Node([
            'nid' => 1,
            'type' => 'article',
            'title' => 'Original Title',
            'uid' => 1,
        ]);
        $embedding1 = $this->embed($node);

        // Change title and re-embed.
        $node->setTitle('Completely Different Title');
        $embedding2 = $this->embed($node);

        // Vectors should differ since text changed.
        $this->assertNotSame($embedding1->vector, $embedding2->vector);

        // Store should only have one entry.
        $stored = $this->vectorStore->get('node', 1);
        $this->assertSame($embedding2->vector, $stored->vector);

        // Search should return only one result for node type.
        $results = $this->embedder->searchSimilar('title', $this->account, 10, 'node');
        $this->assertCount(1, $results);
    }

    #[Test]
    public function cosineSimilarityOfIdenticalVectorsIsOne(): void
    {
        $vector = $this->embeddingProvider->embed('test');
        $similarity = InMemoryVectorStore::cosineSimilarity($vector, $vector);
        $this->assertEqualsWithDelta(1.0, $similarity, 0.0001);
    }

    /**
     * Exploit test (R-gate regression lock), with real persisted `Node`
     * entities rather than mocks: before the access-filter gate,
     * `searchSimilar()` returned every stored embedding regardless of the
     * caller's access. Seed two real nodes, forbid view on one via a real
     * `EntityAccessHandler` policy, and assert only the permitted node's
     * result comes back.
     */
    #[Test]
    public function searchSimilarDropsRealEntitiesTheAccountCannotView(): void
    {
        $permittedNode = new Node([
            'nid' => 1, 'type' => 'article', 'title' => 'Permitted Node', 'uid' => 1,
        ]);
        $forbiddenNode = new Node([
            'nid' => 2, 'type' => 'article', 'title' => 'Forbidden Node', 'uid' => 1,
        ]);

        $this->embed($permittedNode);
        $this->embed($forbiddenNode);

        $gatedAccessHandler = new EntityAccessHandler([
            new VectorSearchIntegrationTestSelectiveForbidPolicy(forbiddenEntityId: 2),
        ]);
        $gatedEmbedder = new EntityEmbedder(
            $this->embeddingProvider,
            $this->vectorStore,
            $gatedAccessHandler,
            $this->entityTypeManager,
        );

        $results = $gatedEmbedder->searchSimilar('Node', $this->account, 10, 'node');

        $this->assertCount(1, $results);
        $this->assertSame(1, $results[0]->embedding->entityId);
    }
}

/**
 * Local test doubles for the `EntityEmbedder` access-filter gate. Mirrors
 * the local-fake pattern already used per-file elsewhere (e.g. provider
 * tests): no shared autoload-dev fixture is wired for ai-vector.
 */
final class VectorSearchIntegrationTestEntityTypeManager implements EntityTypeManagerInterface
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
        return new VectorSearchIntegrationTestRepository($this->entitiesByType[$entityTypeId] ?? []);
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

final class VectorSearchIntegrationTestRepository implements EntityRepositoryInterface
{
    /**
     * @param array<string, EntityInterface> $entities
     */
    public function __construct(private readonly array $entities) {}

    public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface
    {
        return $this->entities[$id] ?? null;
    }

    public function loadWorkingCopy(string $id): ?EntityInterface
    {
        return $this->find($id);
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

final class VectorSearchIntegrationTestAccount implements AccountInterface
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

final class VectorSearchIntegrationTestAllowAllPolicy implements AccessPolicyInterface
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

final class VectorSearchIntegrationTestSelectiveForbidPolicy implements AccessPolicyInterface
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
