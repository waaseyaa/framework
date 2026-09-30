<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Vector\EmbeddingIndexPolicy;
use Waaseyaa\AI\Vector\EmbeddingProviderInterface;
use Waaseyaa\AI\Vector\EmbeddingStorageInterface;
use Waaseyaa\AI\Vector\SemanticIndexWarmer;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;

#[CoversClass(SemanticIndexWarmer::class)]
final class SemanticIndexWarmerTest extends TestCase
{
    #[Test]
    public function warm_and_batch_remove_missing_vectors_and_propagate_failed_indexing(): void
    {
        foreach (['warm', 'warmBatch'] as $method) {
            foreach ([false, true] as $missing) {
                $query = $this->createStub(EntityQueryInterface::class);
                $query->method('accessCheck')->willReturnSelf();
                $query->method('execute')->willReturn(['01']);
                $repository = $this->createStub(EntityRepositoryInterface::class);
                $repository->method('getQuery')->willReturn($query);
                $repository->method('findMany')->willReturn($missing ? [] : [new SemanticWarmerEntity('01', 'node', ['title' => 'Published', 'status' => 1, 'workflow_state' => 'published'])]);
                $manager = $this->createStub(EntityTypeManagerInterface::class);
                $manager->method('hasDefinition')->willReturn(true);
                $manager->method('getRepository')->willReturn($repository);
                $provider = $this->createStub(EmbeddingProviderInterface::class);
                $provider->method('embed')->willThrowException(new \RuntimeException('provider unavailable'));
                $storage = $this->createMock(EmbeddingStorageInterface::class);
                $storage->expects(self::never())->method('store');
                $storage->expects(self::once())->method('delete')->with('node', '01');
                $warmer = new SemanticIndexWarmer($manager, $storage, $provider, indexPolicy: $this->nodePolicy());
                $failure = null;
                try {
                    $report = $warmer->$method(['node']);
                } catch (\RuntimeException $exception) {
                    $failure = $exception;
                }
                if ($missing) {
                    self::assertNull($failure);
                    self::assertSame(1, $report['missing_total']);
                    self::assertSame(0, $report['stored_total']);
                } else {
                    self::assertNotNull($failure, 'Failed refresh must not return a successful report.');
                    self::assertSame('provider unavailable', $failure->getMessage());
                }
            }
        }
    }

    #[Test]
    public function itWarmsDeterministicallyAndRespectsWorkflowVisibility(): void
    {
        $query = new class implements EntityQueryInterface {
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
                return [3, 1, 2];
            }
        };

        $nodeA = new SemanticWarmerEntity(1, 'node', ['title' => 'Anchor', 'status' => 1, 'workflow_state' => 'published']);
        $nodeB = new SemanticWarmerEntity(2, 'node', ['title' => 'Draft', 'status' => 0, 'workflow_state' => 'draft']);
        $nodeC = new SemanticWarmerEntity(3, 'node', ['title' => 'Public', 'status' => 1, 'workflow_state' => 'published']);

        $storage = $this->createStub(EntityStorageInterface::class);

        // C-22 WP3: read path now goes through the canonical repository.
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->method('getQuery')->willReturn($query);
        $repository->expects(self::once())->method('findMany')
            ->with([1, 2, 3])
            ->willReturn([$nodeA, $nodeB, $nodeC]);

        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->expects(self::once())->method('hasDefinition')->with('node')->willReturn(true);
        $manager->expects(self::never())->method('getStorage');
        $manager->expects(self::exactly(2))->method('getRepository')->with('node')->willReturn($repository);

        $provider = $this->createMock(EmbeddingProviderInterface::class);
        $provider->expects($this->exactly(2))
            ->method('embed')
            ->willReturn([0.1, 0.2]);

        $embeddingStorage = $this->createMock(EmbeddingStorageInterface::class);
        $embeddingStorage->expects($this->exactly(2))
            ->method('store')
            ->with(
                'node',
                $this->logicalOr('1', '3'),
                [0.1, 0.2],
            );
        $embeddingStorage->expects($this->once())
            ->method('delete')
            ->with('node', '2');

        $warmer = new SemanticIndexWarmer(
            entityTypeManager: $manager,
            embeddingStorage: $embeddingStorage,
            embeddingProvider: $provider,
            indexPolicy: $this->nodePolicy(),
        );

        $report = $warmer->warm(['node']);

        $this->assertSame('ok', $report['status']);
        $this->assertSame(['node'], $report['requested_entity_types']);
        $this->assertSame(3, $report['processed_total']);
        $this->assertSame(2, $report['stored_total']);
        $this->assertSame(1, $report['removed_total']);
        $this->assertSame(0, $report['missing_total']);
        $this->assertSame(3, $report['by_type']['node']['candidates']);
    }

    #[Test]
    public function itReturnsSkippedStatusWhenProviderIsMissing(): void
    {
        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->expects($this->never())->method('hasDefinition');

        $embeddingStorage = $this->createMock(EmbeddingStorageInterface::class);
        $embeddingStorage->expects($this->never())->method('store');
        $embeddingStorage->expects($this->never())->method('delete');

        $warmer = new SemanticIndexWarmer(
            entityTypeManager: $manager,
            embeddingStorage: $embeddingStorage,
            embeddingProvider: null,
            indexPolicy: $this->nodePolicy(),
        );

        $report = $warmer->warm(['node']);

        $this->assertSame('skipped_no_provider', $report['status']);
        $this->assertSame(0, $report['processed_total']);
        $this->assertSame(0, $report['stored_total']);
    }

    #[Test]
    public function itSupportsResumableBatchRefreshCursors(): void
    {
        $query = new class implements EntityQueryInterface {
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
                return [1, 2, 3];
            }
        };

        $node1 = new SemanticWarmerEntity(1, 'node', ['title' => 'One', 'status' => 1, 'workflow_state' => 'published']);
        $node2 = new SemanticWarmerEntity(2, 'node', ['title' => 'Two', 'status' => 0, 'workflow_state' => 'draft']);
        $node3 = new SemanticWarmerEntity(3, 'node', ['title' => 'Three', 'status' => 1, 'workflow_state' => 'published']);

        $storage = $this->createStub(EntityStorageInterface::class);
        $storage->method('getQuery')->willReturn($query);

        // C-22 WP3: read path now goes through the canonical repository.
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('getQuery')->willReturn($query);
        $repository->method('findMany')->willReturnCallback(
            static fn(array $ids): array => array_values(array_filter([
                1 => $node1,
                2 => $node2,
                3 => $node3,
            ], static fn($entity, $id): bool => in_array($id, $ids, true), ARRAY_FILTER_USE_BOTH)),
        );

        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->expects(self::exactly(2))->method('hasDefinition')->with('node')->willReturn(true);
        $manager->expects(self::never())->method('getStorage');
        $manager->expects(self::exactly(4))->method('getRepository')->with('node')->willReturn($repository);

        $provider = $this->createStub(EmbeddingProviderInterface::class);
        $provider->method('embed')->willReturn([0.1, 0.2]);

        $embeddingStorage = $this->createMock(EmbeddingStorageInterface::class);
        $embeddingStorage->expects($this->exactly(2))->method('store');
        $embeddingStorage->expects($this->once())->method('delete')->with('node', '2');

        $warmer = new SemanticIndexWarmer(
            entityTypeManager: $manager,
            embeddingStorage: $embeddingStorage,
            embeddingProvider: $provider,
            indexPolicy: $this->nodePolicy(),
        );

        $first = $warmer->warmBatch(['node'], 2, null);
        $this->assertSame(2, $first['batch_processed']);
        $this->assertIsArray($first['next_cursor']);
        $this->assertSame(['type_index' => 0, 'offset' => 2], $first['next_cursor']);

        $second = $warmer->warmBatch(['node'], 2, $first['next_cursor']);
        $this->assertSame(1, $second['batch_processed']);
        $this->assertNull($second['next_cursor']);
    }

    #[Test]
    public function refreshing_a_newly_excluded_type_removes_its_existing_vectors_without_embedding(): void
    {
        $query = new class implements EntityQueryInterface {
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
                return [7];
            }
        };
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('getQuery')->willReturn($query);
        $repository->method('findMany')->willReturn([
            new SemanticWarmerEntity(7, 'user', ['name' => 'Private person']),
        ]);
        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->expects(self::once())->method('hasDefinition')->with('user')->willReturn(true);
        $manager->expects(self::exactly(2))->method('getRepository')->with('user')->willReturn($repository);

        $provider = $this->createMock(EmbeddingProviderInterface::class);
        $provider->expects(self::never())->method('embed');
        $storage = $this->createMock(EmbeddingStorageInterface::class);
        $storage->expects(self::never())->method('store');
        $storage->expects(self::once())->method('delete')->with('user', '7');

        $report = new SemanticIndexWarmer(
            entityTypeManager: $manager,
            embeddingStorage: $storage,
            embeddingProvider: $provider,
            indexPolicy: $this->nodePolicy(),
        )->warm(['user']);

        self::assertSame(0, $report['stored_total']);
        self::assertSame(1, $report['removed_total']);
    }

    #[Test]
    public function refreshing_a_newly_excluded_type_purges_vectors_without_a_provider(): void
    {
        $query = new class implements EntityQueryInterface {
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
                return [7];
            }
        };
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('getQuery')->willReturn($query);
        $repository->method('findMany')->willReturn([
            new SemanticWarmerEntity(7, 'user', ['name' => 'Private person']),
        ]);
        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->expects(self::once())->method('hasDefinition')->with('user')->willReturn(true);
        $manager->expects(self::exactly(2))->method('getRepository')->with('user')->willReturn($repository);

        $storage = $this->createMock(EmbeddingStorageInterface::class);
        $storage->expects(self::never())->method('store');
        $storage->expects(self::once())->method('delete')->with('user', '7');

        $report = new SemanticIndexWarmer(
            entityTypeManager: $manager,
            embeddingStorage: $storage,
            embeddingProvider: null,
            indexPolicy: $this->nodePolicy(),
        )->warm(['user']);

        self::assertSame('ok', $report['status']);
        self::assertSame(0, $report['stored_total']);
        self::assertSame(1, $report['removed_total']);
    }

    private function nodePolicy(): EmbeddingIndexPolicy
    {
        return EmbeddingIndexPolicy::fromArray([
            'ai' => [
                'vector_index' => [
                    'node' => [
                        'fields' => ['label', 'title', 'body', 'description'],
                        'allow_external' => true,
                    ],
                ],
            ],
        ]);
    }
}

final readonly class SemanticWarmerEntity implements EntityInterface
{
    public function __construct(
        private int|string|null $id,
        private string $entityTypeId,
        private array $values,
    ) {}

    public function id(): int|string|null
    {
        return $this->id;
    }
    public function uuid(): string
    {
        return 'uuid';
    }
    public function label(): string
    {
        return (string) ($this->values['title'] ?? '');
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
        return $this->values[$name] ?? null;
    }
    public function set(string $name, mixed $value): static
    {
        throw new \LogicException('Readonly');
    }
    public function toArray(): array
    {
        return $this->values;
    }
    public function language(): string
    {
        return 'en';
    }
}
