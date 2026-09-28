<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Provider\IngestSearchSemanticServiceProvider;
use Waaseyaa\CLI\Provider\SemanticServiceProvider;
use Waaseyaa\Foundation\ServiceProvider\Capability\RequiresOptionalPackagesInterface;

#[CoversClass(SemanticServiceProvider::class)]
final class SemanticServiceProviderTest extends TestCase
{
    #[Test]
    public function semantic_commands_are_not_part_of_the_core_ingest_and_search_provider(): void
    {
        $names = array_map(static fn($command): string => $command->name, iterator_to_array((new IngestSearchSemanticServiceProvider())->consoleCommands()));

        self::assertNotContains('semantic:warm', $names);
        self::assertNotContains('semantic:refresh', $names);
        self::assertContains('ingest:run', $names);
        self::assertContains('search:reindex', $names);
    }

    #[Test]
    public function installed_but_disabled_registers_no_semantic_commands(): void
    {
        $provider = new SemanticServiceProvider();
        $provider->setKernelContext('/tmp/test', [], []);

        self::assertInstanceOf(RequiresOptionalPackagesInterface::class, $provider);
        self::assertSame([], iterator_to_array($provider->consoleCommands()));
    }

    #[Test]
    public function installed_and_enabled_registers_both_semantic_commands(): void
    {
        $provider = new SemanticServiceProvider();
        $provider->setKernelContext('/tmp/test', ['ai' => ['vector_enabled' => true]], []);

        $names = array_map(static fn($command): string => $command->name, iterator_to_array($provider->consoleCommands()));

        self::assertSame(['semantic:warm', 'semantic:refresh'], $names);
    }
}
