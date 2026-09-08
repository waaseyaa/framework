<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Provider\SiteServiceProvider;

#[CoversClass(SiteServiceProvider::class)]
final class ProjectInitCommandDefinitionTest extends TestCase
{
    #[Test]
    public function initializerExposesOnlyTheAcceptedFreshProjectOptions(): void
    {
        $command = SiteServiceProvider::projectInitCommand('/unused/project');
        self::assertSame('project:init', $command->getName());
        $definition = $command->getDefinition();
        self::assertSame(['answers', 'decision-receipt', 'preset', 'project-root', 'config-authorization', 'dry-run', 'json', 'yes'], array_keys($definition->getOptions()));
        foreach (['answers', 'decision-receipt', 'preset', 'project-root', 'config-authorization'] as $name) {
            self::assertTrue($definition->getOption($name)->isValueRequired());
        }
        foreach (['dry-run', 'json', 'yes'] as $name) {
            self::assertFalse($definition->getOption($name)->acceptValue());
        }
        self::assertSame('y', $definition->getOption('yes')->getShortcut());
        self::assertFalse($definition->hasOption('upgrade'));
    }

    #[Test]
    public function ordinaryDiscoveryIncludesTheSameInitializerDefinitionExactlyOnce(): void
    {
        $commands = iterator_to_array(new SiteServiceProvider('/unused/project')->consoleCommands());
        $initializers = array_values(array_filter($commands, static fn($command): bool => $command->getName() === 'project:init'));
        self::assertCount(1, $initializers);
        self::assertSame(
            SiteServiceProvider::projectInitCommand('/unused/project')->getDefinition()->getSynopsis(),
            $initializers[0]->getDefinition()->getSynopsis(),
        );
    }
}
