<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Handler\SchemaListHandler;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Foundation\Schema\SchemaEntry;
use Waaseyaa\Foundation\Schema\SchemaRegistryInterface;

#[CoversClass(SchemaListHandler::class)]
final class SchemaListHandlerTest extends TestCase
{
    #[Test]
    public function outputsNoSchemasFoundWhenRegistryIsEmpty(): void
    {
        $tester = $this->createTester([]);
        $tester->execute([]);

        $this->assertStringContainsString('No schemas found', $tester->getStdout());
        $this->assertSame(0, $tester->getExitCode());
    }

    #[Test]
    public function outputsSchemaDetails(): void
    {
        $tester = $this->createTester([
            new SchemaEntry('core.note', '0.1.0', 'liberal', '/defaults/core.note.schema.json'),
        ]);
        $tester->execute([]);

        $this->assertStringContainsString('core.note', $tester->getStdout());
        $this->assertStringContainsString('0.1.0', $tester->getStdout());
        $this->assertStringContainsString('liberal', $tester->getStdout());
        $this->assertSame(0, $tester->getExitCode());
    }

    #[Test]
    public function outputsMultipleSchemas(): void
    {
        $tester = $this->createTester([
            new SchemaEntry('core.note', '0.1.0', 'liberal', '/defaults/core.note.schema.json'),
            new SchemaEntry('core.article', '0.2.0', 'strict', '/defaults/core.article.schema.json'),
        ]);
        $tester->execute([]);

        $this->assertStringContainsString('core.note', $tester->getStdout());
        $this->assertStringContainsString('core.article', $tester->getStdout());
    }

    /** @param list<SchemaEntry> $entries */
    private function createTester(array $entries): CliTester
    {
        $registry = new class ($entries) implements SchemaRegistryInterface {
            /** @param list<SchemaEntry> $entries */
            public function __construct(private readonly array $entries) {}

            public function list(): array { return $this->entries; }

            public function get(string $id): ?SchemaEntry
            {
                foreach ($this->entries as $entry) {
                    if ($entry->id === $id) {
                        return $entry;
                    }
                }
                return null;
            }
        };

        $handler = new SchemaListHandler($registry);
        $definition = new HandlerCommand(
            name: 'schema:list',
            description: 'List registered schemas with versions and compatibility policy',
            handler: \Closure::fromCallable([$handler, 'execute']),
        );

        $container = new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed { throw new \RuntimeException("Not found: $id"); }
            public function has(string $id): bool { return false; }
        };

        return CliTester::for($definition, $container);
    }
}
