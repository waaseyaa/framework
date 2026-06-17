<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Handler\HealthReportHandler;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Foundation\Diagnostic\HealthCheckerInterface;
use Waaseyaa\Foundation\Diagnostic\HealthCheckResult;

#[CoversClass(HealthReportHandler::class)]
final class HealthReportHandlerTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_report_test_' . uniqid();
        mkdir($this->projectRoot . '/storage/framework', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->projectRoot);
    }

    #[Test]
    public function plainOutputContainsSystemInfo(): void
    {
        $checker = $this->createMock(HealthCheckerInterface::class);
        $checker->method('runAll')->willReturn([
            HealthCheckResult::pass('Database', 'OK'),
        ]);

        $tester = $this->createTester($checker);
        $tester->execute([]);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertStringContainsString('System Information', $tester->getStdout());
        $this->assertStringContainsString('PHP Version', $tester->getStdout());
    }

    #[Test]
    public function jsonOptionOutputsJson(): void
    {
        $checker = $this->createMock(HealthCheckerInterface::class);
        $checker->method('runAll')->willReturn([
            HealthCheckResult::pass('Database', 'OK'),
        ]);

        $tester = $this->createTester($checker);
        $tester->execute(['--json']);

        $decoded = json_decode($tester->getStdout(), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('generated_at', $decoded);
        $this->assertArrayHasKey('system', $decoded);
        $this->assertArrayHasKey('health_checks', $decoded);
    }

    #[Test]
    public function outputOptionWithoutJsonReturnsFailure(): void
    {
        $checker = $this->createMock(HealthCheckerInterface::class);
        $checker->method('runAll')->willReturn([]);

        $outputFile = $this->projectRoot . '/report.json';

        $tester = $this->createTester($checker);
        $tester->execute(['--output=' . $outputFile]);

        $this->assertSame(1, $tester->getExitCode());
        $this->assertStringContainsString('--json', $tester->getStdout());
        $this->assertFileDoesNotExist($outputFile);
    }

    #[Test]
    public function databaseLineShowsResolvedPathNotRawEnvValue(): void
    {
        // Mission request-surface-hardening (#1650) WP02, contract §15: the
        // display surface shows what the kernel actually opens — a relative
        // WAASEYAA_DB resolves against the project root.
        $checker = $this->createMock(HealthCheckerInterface::class);
        $checker->method('runAll')->willReturn([]);

        putenv('WAASEYAA_DB=./storage/health.sqlite');

        try {
            $tester = $this->createTester($checker);
            $tester->execute([]);

            $this->assertStringContainsString($this->projectRoot . '/storage/health.sqlite', $tester->getStdout());
            $this->assertStringNotContainsString('./storage/health.sqlite', $tester->getStdout());
        } finally {
            putenv('WAASEYAA_DB');
        }
    }

    private function createTester(HealthCheckerInterface $checker): CliTester
    {
        $handler = new HealthReportHandler($checker, $this->projectRoot);
        $definition = new HandlerCommand(
            name: 'health:report',
            description: 'Generate a full diagnostic report for operator review',
            options: [
                new HandlerOption(name: 'json', mode: HandlerOptionMode::None, description: 'Output as JSON'),
                new HandlerOption(name: 'output', shortcut: 'o', mode: HandlerOptionMode::Required, description: 'Write report to file'),
            ],
            handler: \Closure::fromCallable([$handler, 'execute']),
        );

        $container = new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed { throw new \RuntimeException("Not found: $id"); }
            public function has(string $id): bool { return false; }
        };

        return CliTester::for($definition, $container);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
