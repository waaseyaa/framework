<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Handler\PerformanceCompareHandler;
use Waaseyaa\CLI\Provider\SchedulePerfServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;

#[CoversClass(PerformanceCompareHandler::class)]
final class PerformanceCompareHandlerTest extends TestCase
{
    private function makeDefinition(): \Waaseyaa\CLI\Command\HandlerCommand
    {
        $provider = new SchedulePerfServiceProvider();
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'perf:compare') {
                return $cmd;
            }
        }

        throw new \RuntimeException('perf:compare command definition not found');
    }

    private function makeContainer(): \Psr\Container\ContainerInterface
    {
        return new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed
            {
                if ($id === PerformanceCompareHandler::class) {
                    return new PerformanceCompareHandler();
                }

                throw new \RuntimeException(sprintf('Container::get(%s) called unexpectedly', $id));
            }

            public function has(string $id): bool
            {
                return $id === PerformanceCompareHandler::class;
            }
        };
    }

    #[Test]
    public function itPassesWhenCurrentMeasurementsMeetBaseline(): void
    {
        $baselinePath = tempnam(sys_get_temp_dir(), 'perf-baseline-');
        $currentPath = tempnam(sys_get_temp_dir(), 'perf-current-');
        self::assertIsString($baselinePath);
        self::assertIsString($currentPath);

        file_put_contents($baselinePath, json_encode([
            'snapshot_hash' => 'hash-a',
            'thresholds_ms' => ['warm' => 500.0, 'semantic_search' => 250.0],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($currentPath, json_encode([
            'snapshot_hash' => 'hash-a',
            'durations_ms' => ['warm' => 200.0, 'semantic_search' => 120.0],
        ], JSON_THROW_ON_ERROR));

        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());
        $tester->execute([
            '--baseline=' . $baselinePath,
            '--current=' . $currentPath,
        ]);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('status: ok', strtolower($tester->getStdout()));

        @unlink($baselinePath);
        @unlink($currentPath);
    }

    #[Test]
    public function itFailsWithExplicitDriftMessages(): void
    {
        $baselinePath = tempnam(sys_get_temp_dir(), 'perf-baseline-');
        $currentPath = tempnam(sys_get_temp_dir(), 'perf-current-');
        self::assertIsString($baselinePath);
        self::assertIsString($currentPath);

        file_put_contents($baselinePath, json_encode([
            'snapshot_hash' => 'hash-a',
            'thresholds_ms' => ['warm' => 100.0],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($currentPath, json_encode([
            'snapshot_hash' => 'hash-b',
            'durations_ms' => ['warm' => 200.0],
        ], JSON_THROW_ON_ERROR));

        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());
        $tester->execute([
            '--baseline=' . $baselinePath,
            '--current=' . $currentPath,
        ]);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('snapshot_hash mismatch', $tester->getStdout());
        self::assertStringContainsString('warm drifted', strtolower($tester->getStdout()));

        @unlink($baselinePath);
        @unlink($currentPath);
    }
}
