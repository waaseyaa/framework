<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Vector\EmbeddingProviderInterface;
use Waaseyaa\AI\Vector\EmbeddingStorageInterface;
use Waaseyaa\AI\Vector\SearchController;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;

#[CoversClass(SearchController::class)]
final class SearchControllerTest extends TestCase
{
    #[Test]
    public function usesSemanticEmbeddingSearchWhenProviderIsConfigured(): void
    {
        $entityA = new SearchEntity(1, 'node', ['title' => 'A']);
        $entityB = new SearchEntity(2, 'node', ['title' => 'B']);

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->expects($this->once())
            ->method('loadMultiple')
            ->with([2, 1])
            ->willReturn([1 => $entityA, 2 => $entityB]);

        $definition = new EntityType(
            id: 'node',
            label: 'Node',
            class: SearchEntity::class,
            keys: ['id' => 'id', 'label' => 'title'],
            fieldDefinitions: ['title' => ['type' => 'string']],
        );

        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('hasDefinition')->willReturnCallback(static fn(string $id): bool => $id === 'node');
        $manager->method('getStorage')->willReturn($storage);
        $manager->method('getDefinition')->willReturn($definition);

        $provider = $this->createMock(EmbeddingProviderInterface::class);
        $provider->expects($this->once())->method('embed')->with('water')->willReturn([0.1, 0.2]);

        $embeddingStorage = $this->createMock(EmbeddingStorageInterface::class);
        $embeddingStorage->expects($this->once())
            ->method('findSimilar')
            ->with([0.1, 0.2], 'node', 5)
            ->willReturn([
                ['id' => '2', 'score' => 0.91],
                ['id' => '1', 'score' => 0.88],
            ]);

        $serializer = new ResourceSerializer($manager);

        $controller = new SearchController(
            entityTypeManager: $manager,
            serializer: $serializer,
            embeddingStorage: $embeddingStorage,
            embeddingProvider: $provider,
        );

        $document = $controller->search('water', 'node', 5);

        $this->assertSame(200, $document->statusCode);
        $array = $document->toArray();
        $this->assertSame('2', $array['data'][0]['id']);
        $this->assertSame('1', $array['data'][1]['id']);
        $this->assertSame('v1.0', $array['meta']['contract_version']);
        $this->assertSame('semantic_search', $array['meta']['contract_surface']);
        $this->assertSame('stable', $array['meta']['contract_stability']);
        $this->assertContains('graph_context_rerank', $array['meta']['semantic_extension_hooks']);
        $this->assertSame('semantic', $array['meta']['mode']);
        $this->assertSame('water', $array['meta']['query']);
    }

    #[Test]
    public function fallsBackToKeywordSearchWhenNoProviderConfigured(): void
    {
        $query = new class implements EntityQueryInterface {
            private int $call = 0;
            public function condition(string $field, mixed $value, string $operator = '='): static { return $this; }
            public function exists(string $field): static { return $this; }
            public function notExists(string $field): static { return $this; }
            public function sort(string $field, string $direction = 'ASC'): static { return $this; }
            public function range(int $offset, int $limit): static { return $this; }
            public function count(): static { return $this; }
            public function accessCheck(bool $check = true): static { return $this; }
            public function execute(): array
            {
                $this->call++;
                return match ($this->call) {
                    1 => [5],
                    2 => [6],
                    default => [],
                };
            }
        };

        $entityA = new SearchEntity(5, 'node', ['title' => 'Five']);
        $entityB = new SearchEntity(6, 'node', ['title' => 'Six']);

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('getQuery')->willReturn($query);
        $storage->expects($this->once())
            ->method('loadMultiple')
            ->with([5, 6])
            ->willReturn([5 => $entityA, 6 => $entityB]);

        $definition = new EntityType(
            id: 'node',
            label: 'Node',
            class: SearchEntity::class,
            keys: ['id' => 'id', 'label' => 'title'],
            fieldDefinitions: ['title' => ['type' => 'string']],
        );

        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('hasDefinition')->willReturnCallback(static fn(string $id): bool => $id === 'node');
        $manager->method('getStorage')->willReturn($storage);
        $manager->method('getDefinition')->willReturn($definition);

        $embeddingStorage = $this->createMock(EmbeddingStorageInterface::class);
        $embeddingStorage->expects($this->never())->method('findSimilar');

        $serializer = new ResourceSerializer($manager);

        $controller = new SearchController(
            entityTypeManager: $manager,
            serializer: $serializer,
            embeddingStorage: $embeddingStorage,
            embeddingProvider: null,
        );

        $document = $controller->search('water', 'node', 10);
        $array = $document->toArray();

        $this->assertSame('5', $array['data'][0]['id']);
        $this->assertSame('6', $array['data'][1]['id']);
        $this->assertSame('keyword', $array['meta']['mode']);
    }

    #[Test]
    public function fallsBackToKeywordModeWhenSemanticProviderFails(): void
    {
        $query = new class implements EntityQueryInterface {
            private int $call = 0;
            public function condition(string $field, mixed $value, string $operator = '='): static { return $this; }
            public function exists(string $field): static { return $this; }
            public function notExists(string $field): static { return $this; }
            public function sort(string $field, string $direction = 'ASC'): static { return $this; }
            public function range(int $offset, int $limit): static { return $this; }
            public function count(): static { return $this; }
            public function accessCheck(bool $check = true): static { return $this; }
            public function execute(): array
            {
                $this->call++;
                return $this->call === 1 ? [42] : [];
            }
        };

        $entity = new SearchEntity(42, 'node', ['title' => 'Fallback Result']);

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('getQuery')->willReturn($query);
        $storage->expects($this->once())
            ->method('loadMultiple')
            ->with([42])
            ->willReturn([42 => $entity]);

        $definition = new EntityType(
            id: 'node',
            label: 'Node',
            class: SearchEntity::class,
            keys: ['id' => 'id', 'label' => 'title'],
            fieldDefinitions: ['title' => ['type' => 'string']],
        );

        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('hasDefinition')->willReturnCallback(static fn(string $id): bool => $id === 'node');
        $manager->method('getStorage')->willReturn($storage);
        $manager->method('getDefinition')->willReturn($definition);

        $provider = $this->createMock(EmbeddingProviderInterface::class);
        $provider->expects($this->once())
            ->method('embed')
            ->with('water')
            ->willThrowException(new \RuntimeException('provider unavailable'));

        $embeddingStorage = $this->createMock(EmbeddingStorageInterface::class);
        $embeddingStorage->expects($this->never())->method('findSimilar');

        $controller = new SearchController(
            entityTypeManager: $manager,
            serializer: new ResourceSerializer($manager),
            embeddingStorage: $embeddingStorage,
            embeddingProvider: $provider,
        );

        $document = $controller->search('water', 'node', 10);
        $array = $document->toArray();

        $this->assertSame('keyword', $array['meta']['mode']);
        $this->assertSame('semantic', $array['meta']['requested_mode']);
        $this->assertSame('embedding_provider_error', $array['meta']['fallback_reason']);
        $this->assertSame('42', $array['data'][0]['id']);
    }

    #[Test]
    public function semanticModeCanRerankByRelationshipGraphContext(): void
    {
        $entityA = new SearchEntity(1, 'node', ['title' => 'A']);
        $entityB = new SearchEntity(2, 'node', ['title' => 'B']);

        $nodeStorage = $this->createMock(EntityStorageInterface::class);
        $nodeStorage->expects($this->once())
            ->method('loadMultiple')
            ->with([2, 1])
            ->willReturn([1 => $entityA, 2 => $entityB]);

        $relationshipQuery = new class implements EntityQueryInterface {
            public function condition(string $field, mixed $value, string $operator = '='): static { return $this; }
            public function exists(string $field): static { return $this; }
            public function notExists(string $field): static { return $this; }
            public function sort(string $field, string $direction = 'ASC'): static { return $this; }
            public function range(int $offset, int $limit): static { return $this; }
            public function count(): static { return $this; }
            public function accessCheck(bool $check = true): static { return $this; }
            public function execute(): array { return [99]; }
        };

        $relationshipStorage = $this->createMock(EntityStorageInterface::class);
        $relationshipStorage->method('getQuery')->willReturn($relationshipQuery);
        $relationshipStorage->expects($this->once())
            ->method('loadMultiple')
            ->with([99])
            ->willReturn([
                99 => new SearchEntity(99, 'relationship', [
                    'status' => 1,
                    'from_entity_type' => 'node',
                    'from_entity_id' => '2',
                    'to_entity_type' => 'node',
                    'to_entity_id' => '999',
                ]),
            ]);

        $definition = new EntityType(
            id: 'node',
            label: 'Node',
            class: SearchEntity::class,
            keys: ['id' => 'id', 'label' => 'title'],
            fieldDefinitions: ['title' => ['type' => 'string']],
        );

        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('hasDefinition')->willReturnCallback(static fn(string $id): bool => in_array($id, ['node', 'relationship'], true));
        $manager->method('getStorage')->willReturnCallback(
            static fn(string $id): EntityStorageInterface => $id === 'relationship' ? $relationshipStorage : $nodeStorage,
        );
        $manager->method('getDefinition')->willReturn($definition);

        $provider = $this->createMock(EmbeddingProviderInterface::class);
        $provider->expects($this->once())->method('embed')->with('water')->willReturn([0.1, 0.2]);

        $embeddingStorage = $this->createMock(EmbeddingStorageInterface::class);
        $embeddingStorage->expects($this->once())
            ->method('findSimilar')
            ->with([0.1, 0.2], 'node', 5)
            ->willReturn([
                ['id' => '1', 'score' => 0.5000],
                ['id' => '2', 'score' => 0.4995],
            ]);

        $controller = new SearchController(
            entityTypeManager: $manager,
            serializer: new ResourceSerializer($manager),
            embeddingStorage: $embeddingStorage,
            embeddingProvider: $provider,
        );

        $array = $controller->search('water', 'node', 5)->toArray();

        $this->assertSame('2', $array['data'][0]['id']);
        $this->assertSame('1', $array['data'][1]['id']);
        $this->assertSame('semantic+graph_context', $array['meta']['ranking']);
        $this->assertSame(1.0, $array['meta']['ranking_weights']['semantic']);
        $this->assertSame(0.001, $array['meta']['ranking_weights']['graph_context']);
        $this->assertSame(1, $array['meta']['graph_context_counts']['2']);
        $this->assertSame(0, $array['meta']['graph_context_counts']['1']);
        $this->assertSame(0.4995, $array['meta']['score_breakdown']['2']['semantic']);
        $this->assertSame(0.001, $array['meta']['score_breakdown']['2']['graph_context']);
        $this->assertSame(0.5005, $array['meta']['score_breakdown']['2']['combined']);
    }

}

final readonly class SearchEntity implements EntityInterface
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(
        private int|string|null $id,
        private string $entityTypeId,
        private array $values,
    ) {}

    public function id(): int|string|null { return $this->id; }
    public function uuid(): string { return ''; }
    public function label(): string { return (string) ($this->values['title'] ?? ''); }
    public function getEntityTypeId(): string { return $this->entityTypeId; }
    public function bundle(): string { return 'default'; }
    public function isNew(): bool { return false; }
    public function get(string $name): mixed { return $this->values[$name] ?? null; }
    public function set(string $name, mixed $value): static { throw new \LogicException('Readonly'); }
    public function toArray(): array
    {
        if ($this->entityTypeId === 'node') {
            return $this->values + ['id' => $this->id, 'status' => 1, 'workflow_state' => 'published'];
        }

        return $this->values + ['id' => $this->id];
    }
    public function language(): string { return 'en'; }
}
