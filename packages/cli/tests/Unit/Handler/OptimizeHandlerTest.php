<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Handler\OptimizeHandler;
use Waaseyaa\CLI\Testing\CliTester;

#[CoversClass(OptimizeHandler::class)]
final class OptimizeHandlerTest extends TestCase
{
    #[Test]
    public function skips_missing_sub_commands_gracefully(): void
    {
        $handler = new OptimizeHandler(subHandlers: []);
        $tester = $this->createTester($handler);
        $tester->execute([]);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertStringContainsString('Skipping optimize:manifest', $tester->getStdout());
        $this->assertStringContainsString('Skipping optimize:config', $tester->getStdout());
    }

    #[Test]
    public function runs_registered_sub_commands(): void
    {
        $handler = new OptimizeHandler(subHandlers: [
            'optimize:manifest' => static function (\Waaseyaa\CLI\Command\SymfonyCommandIO $io): int {
                $io->writeln('Manifest compiled.');
                return 0;
            },
            'optimize:config' => static function (\Waaseyaa\CLI\Command\SymfonyCommandIO $io): int {
                $io->writeln('Config compiled.');
                return 0;
            },
        ]);

        $tester = $this->createTester($handler);
        $tester->execute([]);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertStringContainsString('Manifest compiled', $tester->getStdout());
        $this->assertStringContainsString('Config compiled', $tester->getStdout());
        $this->assertStringContainsString('All optimizations complete', $tester->getStdout());
    }

    #[Test]
    public function stops_on_sub_command_failure(): void
    {
        $handler = new OptimizeHandler(subHandlers: [
            'optimize:manifest' => static function (\Waaseyaa\CLI\Command\SymfonyCommandIO $io): int {
                return 1;
            },
            'optimize:config' => static function (\Waaseyaa\CLI\Command\SymfonyCommandIO $io): int {
                $io->writeln('Config compiled.');
                return 0;
            },
        ]);

        $tester = $this->createTester($handler);
        $tester->execute([]);

        $this->assertSame(1, $tester->getExitCode());
        $this->assertStringContainsString('optimize:manifest failed', $tester->getStdout());
    }

    #[Test]
    public function reports_when_no_commands_registered(): void
    {
        $handler = new OptimizeHandler(subHandlers: []);
        $tester = $this->createTester($handler);
        $tester->execute([]);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertStringContainsString('No optimization commands are registered', $tester->getStdout());
    }

    private function createTester(OptimizeHandler $handler): CliTester
    {
        $definition = new HandlerCommand(
            name: 'optimize',
            description: 'Run all optimization compilers',
            handler: \Closure::fromCallable([$handler, 'execute']),
        );

        $container = new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed { throw new \RuntimeException("Not found: $id"); }
            public function has(string $id): bool { return false; }
        };

        return CliTester::for($definition, $container);
    }
}
