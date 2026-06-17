<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase21;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Handler\SchemaListHandler;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Foundation\Schema\DefaultsSchemaRegistry;

/**
 * End-to-end schema registry (#207): defaults/ → registry → CLI schema:list.
 */
#[CoversNothing]
final class SchemaRegistryIntegrationTest extends TestCase
{
    private DefaultsSchemaRegistry $registry;

    protected function setUp(): void
    {
        $defaultsDir    = dirname(__DIR__, 3) . '/defaults';
        $this->registry = new DefaultsSchemaRegistry($defaultsDir);
    }

    #[Test]
    public function registryLoadsCoreNoteSchema(): void
    {
        $entry = $this->registry->get('core.note');

        $this->assertNotNull($entry);
        $this->assertSame('core.note', $entry->id);
        $this->assertNotEmpty($entry->version);
        $this->assertSame('liberal', $entry->compatibility);
        $this->assertFileExists($entry->schemaPath);
    }

    #[Test]
    public function registryListContainsCoreNote(): void
    {
        $entries = $this->registry->list();

        $ids = array_map(static fn($e) => $e->id, $entries);
        $this->assertContains('core.note', $ids);
    }

    #[Test]
    public function registryListIsSortedAlphabetically(): void
    {
        $entries = $this->registry->list();
        $ids     = array_map(static fn($e) => $e->id, $entries);
        $sorted  = $ids;
        sort($sorted);

        $this->assertSame($sorted, $ids);
    }

    #[Test]
    public function registryLoadsIngestionEnvelopeSchema(): void
    {
        $entry = $this->registry->get('ingestion.envelope');

        $this->assertNotNull($entry);
        $this->assertSame('ingestion.envelope', $entry->id);
        $this->assertSame('ingestion_envelope', $entry->schemaKind);
        $this->assertSame('experimental', $entry->stability);
        $this->assertSame('liberal', $entry->compatibility);
        $this->assertNotEmpty($entry->version);
        $this->assertFileExists($entry->schemaPath);
    }

    #[Test]
    public function registryListContainsIngestionEnvelope(): void
    {
        $entries = $this->registry->list();

        $ids = array_map(static fn($e) => $e->id, $entries);
        $this->assertContains('ingestion.envelope', $ids);
    }

    #[Test]
    public function coreNoteSchemaDefaultsToEntityKindAndStableStability(): void
    {
        $entry = $this->registry->get('core.note');

        $this->assertNotNull($entry);
        $this->assertSame('entity', $entry->schemaKind);
        $this->assertSame('stable', $entry->stability);
    }

    #[Test]
    public function cliSchemaListOutputsCoreNote(): void
    {
        $handler    = new SchemaListHandler($this->registry);
        $definition = new HandlerCommand(
            name: 'schema:list',
            description: 'List registered schemas with versions and compatibility policy',
            handler: \Closure::fromCallable([$handler, 'execute']),
        );

        $container = new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException("Not found: $id");
            }

            public function has(string $id): bool
            {
                return false;
            }
        };

        $tester = CliTester::for($definition, $container);
        $tester->execute([]);

        $output = $tester->getStdout();
        $this->assertStringContainsString('core.note', $output);
        $this->assertStringContainsString('liberal', $output);
        $this->assertStringContainsString('ingestion.envelope', $output);
        $this->assertStringContainsString('ingestion_envelope', $output);
        $this->assertStringContainsString('experimental', $output);
        $this->assertSame(0, $tester->getExitCode());
    }
}
