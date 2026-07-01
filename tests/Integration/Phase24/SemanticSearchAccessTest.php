<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase24;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AI\Vector\EmbeddingStorageInterface;
use Waaseyaa\AI\Vector\SearchController;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Foundation\Http\Router\SearchRouter;

/**
 * Integration test: SearchRouter threads authenticated account into SearchController,
 * ensuring semantic search respects per-row access control (FR-001, FR-010, #1516).
 */
#[CoversClass(SearchRouter::class)]
final class SemanticSearchAccessTest extends TestCase
{
    /**
     * viewer-a is allowed to view node:1 only; node:2 must be filtered out.
     */
    #[Test]
    public function accessRestrictedRowsAreFilteredForViewerA(): void
    {
        $node1 = new TestSearchNode(1, 'node');
        $node2 = new TestSearchNode(2, 'node');

        $viewerA = $this->makeAccount('viewer-a');

        // Policy: viewer-a may view node:1 only; node:2 is forbidden.
        $policy = new class ($viewerA) implements AccessPolicyInterface {
            public function __construct(private readonly AccountInterface $viewerA) {}

            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === 'node';
            }

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                if ($operation !== 'view') {
                    return AccessResult::neutral('Not a view operation.');
                }
                if ($account->id() === $this->viewerA->id() && (int) $entity->id() === 1) {
                    return AccessResult::allowed('viewer-a may view node:1');
                }

                return AccessResult::forbidden('viewer-a may not view this node');
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral('n/a');
            }
        };

        $accessHandler = new EntityAccessHandler([$policy]);

        [$manager, $embeddingStorage] = $this->buildManagerAndStorage($node1, $node2);
        $serializer = new ResourceSerializer($manager);

        $controller = new SearchController(
            entityTypeManager: $manager,
            serializer: $serializer,
            embeddingStorage: $embeddingStorage,
            embeddingProvider: null,
            accessHandler: $accessHandler,
            account: $viewerA,
        );

        $document = $controller->search('test', 'node', 10);
        $array = $document->toArray();

        $this->assertSame(200, $document->statusCode);
        $ids = array_column($array['data'], 'id');
        // ResourceSerializer uses UUID as the public resource ID for integer-keyed entities.
        $this->assertContains('uuid-1', $ids, 'viewer-a should see node:1');
        $this->assertNotContains('uuid-2', $ids, 'viewer-a should NOT see node:2');
    }

    /**
     * viewer-b is allowed to view both nodes; both must appear in results.
     */
    #[Test]
    public function viewerBReceivesFullResultSet(): void
    {
        $node1 = new TestSearchNode(1, 'node');
        $node2 = new TestSearchNode(2, 'node');

        $viewerB = $this->makeAccount('viewer-b');

        // Policy: viewer-b may view all nodes.
        $policy = new class implements AccessPolicyInterface {
            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === 'node';
            }

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed('viewer-b may view all nodes');
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral('n/a');
            }
        };

        $accessHandler = new EntityAccessHandler([$policy]);

        [$manager, $embeddingStorage] = $this->buildManagerAndStorage($node1, $node2);
        $serializer = new ResourceSerializer($manager);

        $controller = new SearchController(
            entityTypeManager: $manager,
            serializer: $serializer,
            embeddingStorage: $embeddingStorage,
            embeddingProvider: null,
            accessHandler: $accessHandler,
            account: $viewerB,
        );

        $document = $controller->search('test', 'node', 10);
        $array = $document->toArray();

        $this->assertSame(200, $document->statusCode);
        $ids = array_column($array['data'], 'id');
        // ResourceSerializer uses UUID as the public resource ID for integer-keyed entities.
        $this->assertContains('uuid-1', $ids, 'viewer-b should see node:1');
        $this->assertContains('uuid-2', $ids, 'viewer-b should see node:2');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeAccount(string $id): AccountInterface
    {
        return new class ($id) implements AccountInterface {
            public function __construct(private readonly string $accountId) {}
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
                return true;
            }
        };
    }

    /**
     * @return array{0: \Waaseyaa\Entity\EntityTypeManagerInterface, 1: EmbeddingStorageInterface}
     */
    private function buildManagerAndStorage(TestSearchNode $node1, TestSearchNode $node2): array
    {
        // Mock query that returns IDs 1 and 2 for keyword fallback
        $query = new class implements EntityQueryInterface {
            private int $call = 0;
            public function condition(string $field, mixed $value, string $operator = '='): static
            {
                return $this;
            }
            public function exists(string $field): static
            {
                return $this;
            }
            public function notExists(string $field): static
            {
                return $this;
            }
            public function sort(string $field, string $direction = 'ASC'): static
            {
                return $this;
            }
            public function range(int $offset, int $limit): static
            {
                return $this;
            }
            public function count(): static
            {
                return $this;
            }
            public function accessCheck(bool $check = true): static
            {
                return $this;
            }
            public function setAccount(?AccountInterface $account): static
            {
                return $this;
            }
            public function execute(): array
            {
                $this->call++;
                return match ($this->call) {
                    1 => [1],
                    2 => [2],
                    default => [],
                };
            }
        };

        $storage = $this->createStub(EntityStorageInterface::class);
        $storage->method('getQuery')->willReturn($query);

        // C-22 WP3: read path now goes through the canonical repository.
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('getQuery')->willReturn($query);
        $repository->method('findMany')->willReturn([$node1, $node2]);

        $entityType = new EntityType(
            id: 'node',
            label: 'Node',
            class: TestSearchNode::class,
            keys: ['id' => 'id', 'label' => 'title'],
        );

        $manager = $this->createStub(\Waaseyaa\Entity\EntityTypeManagerInterface::class);
        $manager->method('hasDefinition')->willReturnCallback(static fn(string $id): bool => $id === 'node');
        $manager->method('getStorage')->willReturn($storage);
        $manager->method('getRepository')->willReturn($repository);
        $manager->method('getDefinition')->willReturn($entityType);

        // Embedding storage never called (no provider → keyword fallback)
        $embeddingStorage = $this->createStub(EmbeddingStorageInterface::class);

        return [$manager, $embeddingStorage];
    }
}

/**
 * Minimal entity stub for use in integration search tests.
 */
final readonly class TestSearchNode implements EntityInterface
{
    public function __construct(
        private int|string $nodeId,
        private string $entityTypeId,
    ) {}

    public function id(): int|string|null
    {
        return $this->nodeId;
    }
    public function uuid(): string
    {
        return 'uuid-' . $this->nodeId;
    }
    public function label(): string
    {
        return 'Node ' . $this->nodeId;
    }
    public function getEntityTypeId(): string
    {
        return $this->entityTypeId;
    }
    public function bundle(): string
    {
        return 'default';
    }
    public function isNew(): bool
    {
        return false;
    }
    public function get(string $name): mixed
    {
        return match ($name) {
            'id' => $this->nodeId,
            'title' => 'Node ' . $this->nodeId,
            'status' => 1,
            'workflow_state' => 'published',
            default => null,
        };
    }
    public function set(string $name, mixed $value): static
    {
        throw new \LogicException('Readonly');
    }
    public function toArray(): array
    {
        return [
            'id' => $this->nodeId,
            'title' => 'Node ' . $this->nodeId,
            'status' => 1,
            'workflow_state' => 'published',
        ];
    }
    public function language(): string
    {
        return 'en';
    }
}
