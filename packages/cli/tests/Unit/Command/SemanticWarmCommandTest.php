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
use Waaseyaa\CLI\Command\SemanticWarmCommand;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;

#[CoversClass(SemanticWarmCommand::class)]
final class SemanticWarmCommandTest extends TestCase
{
    #[Test]
    public function itFailsWhenNoEmbeddingProviderIsConfigured(): void
    {
        $warmer = new SemanticIndexWarmer(
            entityTypeManager: $this->createMock(EntityTypeManagerInterface::class),
            embeddingStorage: $this->createMock(EmbeddingStorageInterface::class),
            embeddingProvider: null,
        );

        $app = new Application();
        $app->add(new SemanticWarmCommand($warmer));
        $command = $app->find('semantic:warm');

        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Semantic warm status: skipped_no_provider', $tester->getDisplay());
    }

    #[Test]
    public function itEmitsJsonReportWhenRequested(): void
    {
        $query = new class implements EntityQueryInterface {
            public function condition(string $field, mixed $value, string $operator = '='): static { return $this; }
            public function exists(string $field): static { return $this; }
            public function notExists(string $field): static { return $this; }
            public function sort(string $field, string $direction = 'ASC'): static { return $this; }
            public function range(int $offset, int $limit): static { return $this; }
            public function count(): static { return $this; }
            public function accessCheck(bool $check = true): static { return $this; }
            public function execute(): array { return [1]; }
        };

        $entity = new SemanticWarmCommandEntity(1, 'node', ['title' => 'Public', 'status' => 1, 'workflow_state' => 'published']);

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('getQuery')->willReturn($query);
        $storage->method('loadMultiple')->with([1])->willReturn([1 => $entity]);

        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('hasDefinition')->with('node')->willReturn(true);
        $manager->method('getStorage')->with('node')->willReturn($storage);

        $provider = $this->createMock(EmbeddingProviderInterface::class);
        $provider->method('embed')->willReturn([0.2, 0.4]);

        $embeddingStorage = $this->createMock(EmbeddingStorageInterface::class);
        $embeddingStorage->expects($this->once())->method('store')->with('node', '1', [0.2, 0.4]);

        $warmer = new SemanticIndexWarmer(
            entityTypeManager: $manager,
            embeddingStorage: $embeddingStorage,
            embeddingProvider: $provider,
        );

        $app = new Application();
        $app->add(new SemanticWarmCommand($warmer));
        $command = $app->find('semantic:warm');

        $tester = new CommandTester($command);
        $tester->execute(['--json' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('"status": "ok"', $output);
        $this->assertStringContainsString('"processed_total": 1', $output);
    }
}

final readonly class SemanticWarmCommandEntity implements EntityInterface
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
