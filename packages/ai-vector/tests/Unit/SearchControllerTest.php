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
    public function toArray(): array { return $this->values + ['id' => $this->id]; }
    public function language(): string { return 'en'; }
}
