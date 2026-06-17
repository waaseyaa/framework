<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\CLI\Handler\DebugContextHandler;
use Waaseyaa\CLI\Provider\MiscAServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;

#[CoversClass(DebugContextHandler::class)]
final class DebugContextHandlerTest extends TestCase
{
    private function makeDefinition(): \Waaseyaa\CLI\Command\HandlerCommand
    {
        $provider = new MiscAServiceProvider();
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'debug:context') {
                return $cmd;
            }
        }

        throw new \RuntimeException('debug:context command definition not found');
    }

    private function makeContainer(): ContainerInterface
    {
        return new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                if ($id === DebugContextHandler::class) {
                    return new DebugContextHandler();
                }

                throw new \RuntimeException(sprintf('Container::get(%s) called unexpectedly', $id));
            }

            public function has(string $id): bool
            {
                return $id === DebugContextHandler::class;
            }
        };
    }

    #[Test]
    public function rendersDebugPanelJson(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());
        $tester->execute([
            '--entity-type=node',
            '--entity-id=42',
            '--workflow-state=published',
            '--status=1',
            '--relationship-counts=3:2',
            '--view-mode=full',
            '--preview=0',
        ]);

        self::assertSame(0, $tester->getExitCode());
        $output = $tester->getStdout();
        self::assertStringContainsString('debug_panel', $output);
        self::assertStringContainsString('workflow', $output);
        self::assertStringContainsString('node', $output);
        self::assertStringContainsString('published', $output);
        self::assertStringContainsString('traversal', $output);
        self::assertStringContainsString('ssr', $output);
    }

    #[Test]
    public function returnsErrorForInvalidRelationshipCounts(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());
        $tester->execute([
            '--relationship-counts=invalid',
        ]);

        self::assertSame(2, $tester->getExitCode());
        self::assertStringContainsString('Invalid --relationship-counts', $tester->getStderr());
    }
}
