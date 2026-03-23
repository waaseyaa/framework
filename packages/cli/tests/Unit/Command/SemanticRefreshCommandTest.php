<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Waaseyaa\AI\Vector\EmbeddingProviderInterface;
use Waaseyaa\AI\Vector\EmbeddingStorageInterface;
use Waaseyaa\AI\Vector\SemanticIndexWarmer;
use Waaseyaa\CLI\Command\SemanticRefreshCommand;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;

#[CoversClass(SemanticRefreshCommand::class)]
final class SemanticRefreshCommandTest extends TestCase
{
    #[Test]
    public function itReturnsCursorForPartialBatchRun(): void
    {
        $warmer = $this->buildWarmerWithThreeNodes();

        $app = new Application();
        $app->add(new SemanticRefreshCommand($warmer));
        $command = $app->find('semantic:refresh');

        $tester = new CommandTester($command);
        $tester->execute(['--batch-size' => '2', '--json' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $decoded = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $decoded['runs']);
        $this->assertSame(2, $decoded['final']['batch_processed']);
        $this->assertSame(['type_index' => 0, 'offset' => 2], $decoded['final']['next_cursor']);
    }

    #[Test]
    public function untilCompleteConsumesAllBatches(): void
    {
        $warmer = $this->buildWarmerWithThreeNodes();

        $app = new Application();
        $app->add(new SemanticRefreshCommand($warmer));
        $command = $app->find('semantic:refresh');

        $tester = new CommandTester($command);
        $tester->execute(['--batch-size' => '2', '--until-complete' => true, '--json' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $decoded = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(2, $decoded['runs']);
        $this->assertNull($decoded['final']['next_cursor']);
    }

    private function buildWarmerWithThreeNodes(): SemanticIndexWarmer
    {
        $query = new class implements EntityQueryInterface {
            public function condition(string $field, mixed $value, string $operator = '='): static { return $this; }
            public function exists(string $field): static { return $this; }
            public function notExists(string $field): static { return $this; }
            public function sort(string $field, string $direction = 'ASC'): static { return $this; }
            public function range(int $offset, int $limit): static { return $this; }
            public function count(): static { return $this; }
            public function accessCheck(bool $check = true): static { return $this; }
            public function execute(): array { return [1, 2, 3]; }
        };

        $entity1 = new SemanticRefreshEntity(1, 'node', ['title' => 'One', 'status' => 1, 'workflow_state' => 'published']);
        $entity2 = new SemanticRefreshEntity(2, 'node', ['title' => 'Two', 'status' => 0, 'workflow_state' => 'draft']);
        $entity3 = new SemanticRefreshEntity(3, 'node', ['title' => 'Three', 'status' => 1, 'workflow_state' => 'published']);

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('getQuery')->willReturn($query);
        $storage->method('loadMultiple')->willReturnCallback(
            static fn(array $ids): array => array_filter([
                1 => $entity1,
                2 => $entity2,
                3 => $entity3,
            ], static fn($entity, $id): bool => in_array($id, $ids, true), ARRAY_FILTER_USE_BOTH),
        );

        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('hasDefinition')->with('node')->willReturn(true);
        $manager->method('getStorage')->with('node')->willReturn($storage);

        $provider = $this->createMock(EmbeddingProviderInterface::class);
        $provider->method('embed')->willReturn([0.1, 0.2]);

        $embeddingStorage = $this->createMock(EmbeddingStorageInterface::class);

        return new SemanticIndexWarmer(
            entityTypeManager: $manager,
            embeddingStorage: $embeddingStorage,
            embeddingProvider: $provider,
        );
    }
}

final readonly class SemanticRefreshEntity implements EntityInterface
{
    public function __construct(
        private int|string|null $id,
        private string $entityTypeId,
        private array $values,
    ) {}

    public function id(): int|string|null { return $this->id; }
    public function uuid(): string { return 'uuid'; }
    public function label(): string { return (string) ($this->values['title'] ?? ''); }
    public function getEntityTypeId(): string { return $this->entityTypeId; }
    public function bundle(): string { return 'default'; }
    public function isNew(): bool { return false; }
    public function get(string $name): mixed { return $this->values[$name] ?? null; }
    public function set(string $name, mixed $value): static { throw new \LogicException('Readonly'); }
    public function toArray(): array { return $this->values; }
    public function language(): string { return 'en'; }
}
