<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\CLI\Handler\EntityCreateHandler;
use Waaseyaa\CLI\Provider\EntityTypeServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;

#[CoversClass(EntityCreateHandler::class)]
final class EntityCreateHandlerTest extends TestCase
{
    private function makeDefinition(): \Waaseyaa\CLI\Command\HandlerCommand
    {
        $provider = new EntityTypeServiceProvider();
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'entity:create') {
                return $cmd;
            }
        }

        throw new \RuntimeException('entity:create command definition not found');
    }

    private function makeContainer(EntityTypeManagerInterface $manager, string $stdinPath = 'php://stdin'): ContainerInterface
    {
        return new class ($manager, $stdinPath) implements ContainerInterface {
            public function __construct(
                private readonly EntityTypeManagerInterface $manager,
                private readonly string $stdinPath,
            ) {}

            public function get(string $id): mixed
            {
                if ($id === EntityCreateHandler::class) {
                    return new EntityCreateHandler($this->manager, $this->stdinPath);
                }

                throw new \RuntimeException(sprintf('Container::get(%s) called unexpectedly', $id));
            }

            public function has(string $id): bool
            {
                return $id === EntityCreateHandler::class;
            }
        };
    }

    /**
     * @param array<string, mixed> $expectedValues
     */
    private function managerExpecting(array $expectedValues, int $id = 7): EntityTypeManagerInterface
    {
        $entity = $this->createMock(EntityInterface::class);
        $entity->method('id')->willReturn($id);

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->expects($this->once())->method('create')->with($expectedValues)->willReturn($entity);
        $storage->expects($this->once())->method('save')->with($entity);

        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('getStorage')->willReturn($storage);

        return $manager;
    }

    private function tmp(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wcli_');
        file_put_contents($path, $content);
        $this->tmpFiles[] = $path;

        return $path;
    }

    /** @var list<string> */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            @unlink($f);
        }
    }

    #[Test]
    public function createsEntityWithGivenValues(): void
    {
        $mockEntity = $this->createMock(EntityInterface::class);
        $mockEntity->method('id')->willReturn(42);

        $mockStorage = $this->createMock(EntityStorageInterface::class);
        $mockStorage->expects($this->once())
            ->method('create')
            ->with(['title' => 'Test'])
            ->willReturn($mockEntity);
        $mockStorage->expects($this->once())
            ->method('save')
            ->with($mockEntity);

        $mockManager = $this->createMock(EntityTypeManagerInterface::class);
        $mockManager->method('getStorage')
            ->with('node')
            ->willReturn($mockStorage);

        $definition = $this->makeDefinition();
        $tester = CliTester::for($definition, $this->makeContainer($mockManager));
        $tester->executeMap(['entity_type' => 'node', '--values' => '{"title":"Test"}']);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('Created node entity with ID: 42', $tester->getStdout());
    }

    #[Test]
    public function createsEntityWithDefaultEmptyValues(): void
    {
        $mockEntity = $this->createMock(EntityInterface::class);
        $mockEntity->method('id')->willReturn(1);

        $mockStorage = $this->createMock(EntityStorageInterface::class);
        $mockStorage->expects($this->once())
            ->method('create')
            ->with([])
            ->willReturn($mockEntity);
        $mockStorage->expects($this->once())->method('save');

        $mockManager = $this->createMock(EntityTypeManagerInterface::class);
        $mockManager->method('getStorage')->willReturn($mockStorage);

        $definition = $this->makeDefinition();
        $tester = CliTester::for($definition, $this->makeContainer($mockManager));
        $tester->executeMap(['entity_type' => 'node']);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('Created node entity with ID: 1', $tester->getStdout());
    }

    #[Test]
    public function failsOnInvalidJson(): void
    {
        $mockManager = $this->createMock(EntityTypeManagerInterface::class);

        $definition = $this->makeDefinition();
        $tester = CliTester::for($definition, $this->makeContainer($mockManager));
        $tester->executeMap(['entity_type' => 'node', '--values' => 'not-json']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('Invalid JSON', $tester->getStderr());
    }

    #[Test]
    public function createsEntityFromRepeatableFieldFlags(): void
    {
        $manager = $this->managerExpecting(['title' => 'Hello World', 'status' => '1']);
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer($manager));
        $tester->executeMap([
            'entity_type' => 'story',
            '--field' => ['title=Hello World', 'status=1'],
        ]);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
        self::assertStringContainsString('Created story entity with ID: 7', $tester->getStdout());
    }

    #[Test]
    public function createsEntityWithFieldLoadedFromFile(): void
    {
        $bodyPath = $this->tmp("# Heading\n\nA Markdown body with \"quotes\" and 'apostrophes'.\n");
        $manager = $this->managerExpecting([
            'title' => 'Story',
            'body' => "# Heading\n\nA Markdown body with \"quotes\" and 'apostrophes'.\n",
        ]);

        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer($manager));
        $tester->executeMap([
            'entity_type' => 'story',
            '--field' => ['title=Story'],
            '--field-file' => ['body=@' . $bodyPath],
        ]);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
    }

    #[Test]
    public function createsEntityFromValuesFile(): void
    {
        $jsonPath = $this->tmp('{"title":"From File","status":1}');
        $manager = $this->managerExpecting(['title' => 'From File', 'status' => 1]);

        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer($manager));
        $tester->executeMap(['entity_type' => 'story', '--values-file' => $jsonPath]);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
    }

    #[Test]
    public function createsEntityFromStdin(): void
    {
        $stdin = $this->tmp('{"title":"Piped","status":1}');
        $manager = $this->managerExpecting(['title' => 'Piped', 'status' => 1]);

        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer($manager, stdinPath: $stdin));
        $tester->executeMap(['entity_type' => 'story', '--values-file' => '-']);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
    }

    #[Test]
    public function fieldFlagsOverrideValuesAndFieldFileOverridesAll(): void
    {
        $bodyPath = $this->tmp('FILE BODY');
        // base from --values; title overridden by --field; body overridden by --field-file.
        $manager = $this->managerExpecting(['title' => 'override', 'status' => 1, 'body' => 'FILE BODY']);

        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer($manager));
        $tester->executeMap([
            'entity_type' => 'story',
            '--values' => '{"title":"base","status":1,"body":"base body"}',
            '--field' => ['title=override'],
            '--field-file' => ['body=@' . $bodyPath],
        ]);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
    }

    #[Test]
    public function failsOnMalformedFieldFlag(): void
    {
        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer($manager));
        $tester->executeMap(['entity_type' => 'story', '--field' => ['no-equals-sign']]);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('name=value', $tester->getStderr());
    }

    #[Test]
    public function failsOnMissingValuesFile(): void
    {
        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer($manager));
        $tester->executeMap(['entity_type' => 'story', '--values-file' => '/no/such/file.json']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('Could not read', $tester->getStderr());
    }
}
