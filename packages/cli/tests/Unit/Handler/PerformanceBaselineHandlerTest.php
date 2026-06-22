<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Handler\PerformanceBaselineHandler;
use Waaseyaa\CLI\Provider\SchedulePerfServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;

#[CoversClass(PerformanceBaselineHandler::class)]
final class PerformanceBaselineHandlerTest extends TestCase
{
    private function makeDefinition(): \Waaseyaa\CLI\Command\HandlerCommand
    {
        $provider = new SchedulePerfServiceProvider();
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'perf:baseline') {
                return $cmd;
            }
        }

        throw new \RuntimeException('perf:baseline command definition not found');
    }

    private function makeContainer(): \Psr\Container\ContainerInterface
    {
        return new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed
            {
                if ($id === PerformanceBaselineHandler::class) {
                    return new PerformanceBaselineHandler();
                }

                throw new \RuntimeException(sprintf('Container::get(%s) called unexpectedly', $id));
            }

            public function has(string $id): bool
            {
                return $id === PerformanceBaselineHandler::class;
            }
        };
    }

    #[Test]
    public function itBuildsDeterministicBaselinePayload(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());

        $tester->execute([
            '--snapshot-hash=abc123',
            '--threshold=semantic_search:120',
            '--threshold=warm:500',
        ]);

        self::assertSame(0, $tester->getExitCode());
        $decoded = json_decode($tester->getStdout(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('abc123', $decoded['snapshot_hash']);
        self::assertArrayHasKey('semantic_search', $decoded['thresholds_ms']);
        self::assertArrayHasKey('warm', $decoded['thresholds_ms']);
    }

    #[Test]
    public function itRejectsMissingThresholds(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());

        $tester->execute(['--snapshot-hash=abc123']);

        self::assertSame(2, $tester->getExitCode());
        self::assertStringContainsString('At least one valid --threshold', $tester->getStderr());
    }
}
