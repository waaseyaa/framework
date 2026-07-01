<?php

declare(strict_types=1);

namespace Waaseyaa\GraphQL\Tests\Unit\Resolver;

use GraphQL\Deferred;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityRepository;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\GraphQL\Access\GraphQlAccessGuard;
use Waaseyaa\GraphQL\Resolver\ReferenceLoader;

#[CoversClass(ReferenceLoader::class)]
final class ReferenceLoaderTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;
    private InMemoryEntityStorage $storage;
    private AccountInterface $account;

    protected function setUp(): void
    {
        $this->storage = new InMemoryEntityStorage('article');

        $this->entityTypeManager = new EntityTypeManager(
            new EventDispatcher(),
            fn () => $this->storage,
            fn () => new InMemoryEntityRepository($this->storage),
        );
        $this->entityTypeManager->registerCoreEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: \Waaseyaa\Api\Tests\Fixtures\TestEntity::class,
            keys: \Waaseyaa\Api\Tests\Fixtures\TestEntity::definitionKeys(),
        ));

        $this->account = $this->createStub(AccountInterface::class);
        $this->account->method('id')->willReturn(1);
        $this->account->method('hasPermission')->willReturn(true);
        $this->account->method('getRoles')->willReturn(['authenticated']);
        $this->account->method('isAuthenticated')->willReturn(true);
    }

    private function openAccessGuard(): GraphQlAccessGuard
    {
        $policy = new class implements AccessPolicyInterface, FieldAccessPolicyInterface {
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed();
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed();
            }

            public function appliesTo(string $entityTypeId): bool
            {
                return true;
            }

            public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }
        };

        return new GraphQlAccessGuard(new EntityAccessHandler([$policy]), $this->account);
    }

    private function seedArticle(string $title): EntityInterface
    {
        $entity = $this->storage->create(['title' => $title]);
        $entity->enforceIsNew();
        $this->storage->save($entity);

        return $entity;
    }

    // ── Depth cutoff ─────────────────────────────────────────────

    #[Test]
    public function loadReturnsNullWhenDepthExceedsMax(): void
    {
        $this->seedArticle('Deep');
        $loader = new ReferenceLoader($this->entityTypeManager, $this->openAccessGuard(), maxDepth: 2);

        $deferred = $loader->load('article', 1, currentDepth: 2);
        Deferred::runQueue();

        // Depth 2 == maxDepth 2 => cutoff (>= comparison)
        self::assertNull($deferred->result);
    }

    #[Test]
    public function loadResolvesWhenDepthBelowMax(): void
    {
        $entity = $this->seedArticle('Shallow');
        $loader = new ReferenceLoader($this->entityTypeManager, $this->openAccessGuard(), maxDepth: 3);

        $deferred = $loader->load('article', $entity->id(), currentDepth: 1);
        Deferred::runQueue();

        self::assertNotNull($deferred->result);
        self::assertSame('Shallow', $deferred->result['title']);
        self::assertSame(1, $deferred->result['_graphql_depth']);
    }

    // ── Unknown entity type ──────────────────────────────────────

    #[Test]
    public function loadReturnsNullForUnknownEntityType(): void
    {
        $loader = new ReferenceLoader($this->entityTypeManager, $this->openAccessGuard());

        $deferred = $loader->load('nonexistent_type', 1, currentDepth: 0);
        Deferred::runQueue();

        self::assertNull($deferred->result);
    }

    // ── Batch deduplication ──────────────────────────────────────

    #[Test]
    public function loadBatchesMultipleRequestsForSameType(): void
    {
        $a = $this->seedArticle('Article A');
        $b = $this->seedArticle('Article B');

        $loader = new ReferenceLoader($this->entityTypeManager, $this->openAccessGuard());

        $deferredA = $loader->load('article', $a->id(), currentDepth: 0);
        $deferredB = $loader->load('article', $b->id(), currentDepth: 0);
        Deferred::runQueue();

        self::assertNotNull($deferredA->result);
        self::assertNotNull($deferredB->result);
        self::assertSame('Article A', $deferredA->result['title']);
        self::assertSame('Article B', $deferredB->result['title']);
    }

    #[Test]
    public function loadDeduplicatesSameIdInBuffer(): void
    {
        $entity = $this->seedArticle('Deduplicated');

        $loader = new ReferenceLoader($this->entityTypeManager, $this->openAccessGuard());

        // Queue same entity twice
        $deferred1 = $loader->load('article', $entity->id(), currentDepth: 0);
        $deferred2 = $loader->load('article', $entity->id(), currentDepth: 0);
        Deferred::runQueue();

        self::assertNotNull($deferred1->result);
        self::assertNotNull($deferred2->result);
        self::assertSame($deferred1->result['title'], $deferred2->result['title']);
    }

    // ── Access filtering in deferred resolution ──────────────────

    #[Test]
    public function loadReturnsNullWhenViewAccessDenied(): void
    {
        $entity = $this->seedArticle('Secret');

        // No policies = Neutral = not isAllowed()
        $guard = new GraphQlAccessGuard(new EntityAccessHandler([]), $this->account);
        $loader = new ReferenceLoader($this->entityTypeManager, $guard);

        $deferred = $loader->load('article', $entity->id(), currentDepth: 0);
        Deferred::runQueue();

        self::assertNull($deferred->result);
    }

    #[Test]
    public function loadFiltersFieldsOnResolvedEntity(): void
    {
        $entity = $this->seedArticle('Filtered');

        $policy = new class implements AccessPolicyInterface, FieldAccessPolicyInterface {
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed();
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed();
            }

            public function appliesTo(string $entityTypeId): bool
            {
                return true;
            }

            public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
            {
                // Forbid the uuid field
                return $fieldName === 'uuid' ? AccessResult::forbidden('hidden') : AccessResult::neutral();
            }
        };

        $guard = new GraphQlAccessGuard(new EntityAccessHandler([$policy]), $this->account);
        $loader = new ReferenceLoader($this->entityTypeManager, $guard);

        $deferred = $loader->load('article', $entity->id(), currentDepth: 0);
        Deferred::runQueue();

        self::assertNotNull($deferred->result);
        self::assertArrayHasKey('title', $deferred->result);
        self::assertArrayNotHasKey('uuid', $deferred->result);
    }

    // ── Cache reuse ──────────────────────────────────────────────

    #[Test]
    public function loadReusesAlreadyLoadedEntities(): void
    {
        $entity = $this->seedArticle('Cached');

        $loader = new ReferenceLoader($this->entityTypeManager, $this->openAccessGuard());

        // First load
        $deferred1 = $loader->load('article', $entity->id(), currentDepth: 0);
        Deferred::runQueue();
        self::assertNotNull($deferred1->result);

        // Second load should reuse cache (not re-query storage)
        $deferred2 = $loader->load('article', $entity->id(), currentDepth: 0);
        Deferred::runQueue();
        self::assertNotNull($deferred2->result);
        self::assertSame($deferred1->result['title'], $deferred2->result['title']);
    }

    #[Test]
    public function loadReturnsNullForNonexistentEntity(): void
    {
        $loader = new ReferenceLoader($this->entityTypeManager, $this->openAccessGuard());

        $deferred = $loader->load('article', 999, currentDepth: 0);
        Deferred::runQueue();

        self::assertNull($deferred->result);
    }

    #[Test]
    public function loadUsesSingleLoadMultiplePerEntityTypePerDeferredQueue(): void
    {
        $articleStorage = new InMemoryEntityStorage('article');
        $articleRepository = new CountingInMemoryRepository($articleStorage);

        $manager = new EntityTypeManager(
            new EventDispatcher(),
            static fn (EntityTypeInterface $_def): InMemoryEntityStorage => $articleStorage,
            // C-22 WP3: read path now goes through the canonical repository.
            static fn (): CountingInMemoryRepository => $articleRepository,
        );
        $manager->registerCoreEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: \Waaseyaa\Api\Tests\Fixtures\TestEntity::class,
            keys: \Waaseyaa\Api\Tests\Fixtures\TestEntity::definitionKeys(),
        ));

        foreach (['A', 'B', 'C'] as $title) {
            $e = $articleStorage->create(['title' => $title]);
            $e->enforceIsNew();
            $articleStorage->save($e);
        }

        $guard = $this->openAccessGuard();
        $loader = new ReferenceLoader($manager, $guard);

        $d1 = $loader->load('article', 1, 0);
        $d2 = $loader->load('article', 2, 0);
        $d3 = $loader->load('article', 3, 0);
        Deferred::runQueue();

        self::assertSame(1, $articleRepository->findManyInvocations);
        self::assertNotNull($d1->result);
        self::assertNotNull($d2->result);
        self::assertNotNull($d3->result);
    }
}

final class CountingInMemoryRepository implements EntityRepositoryInterface
{
    public int $findManyInvocations = 0;

    private readonly InMemoryEntityRepository $inner;

    public function __construct(InMemoryEntityStorage $storage)
    {
        $this->inner = new InMemoryEntityRepository($storage);
    }

    public function findMany(array $ids, ?string $langcode = null, bool $fallback = false): array
    {
        ++$this->findManyInvocations;

        return $this->inner->findMany($ids, $langcode, $fallback);
    }

    public function create(array $values = []): \Waaseyaa\Entity\EntityInterface
    {
        return $this->inner->create($values);
    }

    public function find(string $id, ?string $langcode = null, bool $fallback = false): ?\Waaseyaa\Entity\EntityInterface
    {
        return $this->inner->find($id, $langcode, $fallback);
    }

    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array
    {
        return $this->inner->findBy($criteria, $orderBy, $limit);
    }

    public function getQuery(): \Waaseyaa\Entity\Storage\EntityQueryInterface
    {
        return $this->inner->getQuery();
    }

    public function save(\Waaseyaa\Entity\EntityInterface $entity, bool $validate = true): int
    {
        return $this->inner->save($entity, $validate);
    }

    public function delete(\Waaseyaa\Entity\EntityInterface $entity): void
    {
        $this->inner->delete($entity);
    }

    public function exists(string $id): bool
    {
        return $this->inner->exists($id);
    }

    public function count(array $criteria = []): int
    {
        return $this->inner->count($criteria);
    }

    public function loadRevision(string $entityId, int $revisionId): ?\Waaseyaa\Entity\EntityInterface
    {
        return $this->inner->loadRevision($entityId, $revisionId);
    }

    public function rollback(string $entityId, int $targetRevisionId): \Waaseyaa\Entity\EntityInterface
    {
        return $this->inner->rollback($entityId, $targetRevisionId);
    }

    public function listRevisions(string $entityId): array
    {
        return $this->inner->listRevisions($entityId);
    }

    public function setCurrentRevision(string $entityId, int $revisionId): \Waaseyaa\Entity\EntityInterface
    {
        return $this->inner->setCurrentRevision($entityId, $revisionId);
    }

    public function loadPublishedRevision(string $entityId): ?\Waaseyaa\Entity\EntityInterface
    {
        return $this->inner->loadPublishedRevision($entityId);
    }

    public function setPublishedRevision(string $entityId, int $revisionId): \Waaseyaa\Entity\EntityInterface
    {
        return $this->inner->setPublishedRevision($entityId, $revisionId);
    }

    public function saveMany(array $entities, bool $validate = true): array
    {
        return $this->inner->saveMany($entities, $validate);
    }

    public function deleteMany(array $entities): int
    {
        return $this->inner->deleteMany($entities);
    }

    public function findTranslations(\Waaseyaa\Entity\EntityInterface $entity): array
    {
        return $this->inner->findTranslations($entity);
    }

    public function saveTranslation(string $entityId, string $langcode, array $values, ?string $log = null): int
    {
        return $this->inner->saveTranslation($entityId, $langcode, $values, $log);
    }

    public function loadTranslation(string $entityId, string $langcode): ?\Waaseyaa\Entity\EntityInterface
    {
        return $this->inner->loadTranslation($entityId, $langcode);
    }

    public function listTranslationRevisions(string $entityId, string $langcode): array
    {
        return $this->inner->listTranslationRevisions($entityId, $langcode);
    }
}
