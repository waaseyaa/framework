<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Vector\EmbeddingProviderInterface;
use Waaseyaa\AI\Vector\EmbeddingStorageInterface;
use Waaseyaa\AI\Vector\SemanticIndexWarmer;
use Waaseyaa\CLI\Handler\SemanticWarmHandler;
use Waaseyaa\CLI\Provider\IngestSearchSemanticServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;

#[CoversClass(SemanticWarmHandler::class)]
final class SemanticWarmCommandTest extends TestCase
{
    private function makeTester(SemanticIndexWarmer $warmer): CliTester
    {
        $provider = new IngestSearchSemanticServiceProvider();
        $definition = null;
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'semantic:warm') {
                $definition = $cmd;
                break;
            }
        }
        self::assertNotNull($definition, 'semantic:warm command definition must exist');

        $handler = new SemanticWarmHandler($warmer);
        $container = new class ($handler) implements ContainerInterface {
            public function __construct(private readonly SemanticWarmHandler $handler) {}

            public function get(string $id): mixed
            {
                return $this->handler;
            }

            public function has(string $id): bool
            {
                return $id === SemanticWarmHandler::class;
            }
        };

        return CliTester::for($definition, $container);
    }

    #[Test]
    public function itFailsWhenNoEmbeddingProviderIsConfigured(): void
    {
        $warmer = new SemanticIndexWarmer(
            entityTypeManager: $this->createMock(EntityTypeManagerInterface::class),
            embeddingStorage: $this->createMock(EmbeddingStorageInterface::class),
            embeddingProvider: null,
        );

        $tester = $this->makeTester($warmer);
        $tester->execute([]);

        $this->assertSame(1, $tester->getExitCode());
        $this->assertStringContainsString('Semantic warm status: skipped_no_provider', $tester->getStdout());
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
            public function setAccount(?AccountInterface $account): static { return $this; }
            public function execute(): array { return [1]; }
        };

        $entity = new SemanticWarmCommandEntity(1, 'node', ['title' => 'Public', 'status' => 1, 'workflow_state' => 'published']);

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('getQuery')->willReturn($query);

        // C-22 WP3: read path now goes through the canonical repository.
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->method('getQuery')->willReturn($query);
        $repository->method('findMany')->with([1])->willReturn([$entity]);

        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('hasDefinition')->with('node')->willReturn(true);
        $manager->method('getStorage')->with('node')->willReturn($storage);
        $manager->method('getRepository')->with('node')->willReturn($repository);

        $embeddingProvider = $this->createMock(EmbeddingProviderInterface::class);
        $embeddingProvider->method('embed')->willReturn([0.2, 0.4]);

        $embeddingStorage = $this->createMock(EmbeddingStorageInterface::class);
        $embeddingStorage->expects($this->once())->method('store')->with('node', '1', [0.2, 0.4]);

        $warmer = new SemanticIndexWarmer(
            entityTypeManager: $manager,
            embeddingStorage: $embeddingStorage,
            embeddingProvider: $embeddingProvider,
        );

        $tester = $this->makeTester($warmer);
        $tester->executeMap(['--json' => true]);

        $this->assertSame(0, $tester->getExitCode());
        $output = $tester->getStdout();
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
