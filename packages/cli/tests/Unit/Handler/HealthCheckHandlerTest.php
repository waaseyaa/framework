<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Handler\HealthCheckHandler;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Foundation\Diagnostic\DiagnosticCode;
use Waaseyaa\Foundation\Diagnostic\HealthCheckerInterface;
use Waaseyaa\Foundation\Diagnostic\HealthCheckResult;

#[CoversClass(HealthCheckHandler::class)]
final class HealthCheckHandlerTest extends TestCase
{
    #[Test]
    public function allPassingReturnsSuccessAndOutput(): void
    {
        $checker = $this->createMock(HealthCheckerInterface::class);
        $checker->method('runAll')->willReturn([
            HealthCheckResult::pass('Database', 'DB is accessible.'),
            HealthCheckResult::pass('Entity types', '3 types registered.'),
        ]);

        $tester = $this->createTester($checker);
        $tester->execute([]);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertStringContainsString('Database', $tester->getStdout());
        $this->assertStringContainsString('All health checks passed', $tester->getStdout());
    }

    #[Test]
    public function warningsReturnExitCode1(): void
    {
        $checker = $this->createMock(HealthCheckerInterface::class);
        $checker->method('runAll')->willReturn([
            HealthCheckResult::pass('Database', 'OK'),
            HealthCheckResult::warn('Cache', DiagnosticCode::CACHE_DIRECTORY_UNWRITABLE, 'Not writable.'),
        ]);

        $tester = $this->createTester($checker);
        $tester->execute([]);

        $this->assertSame(1, $tester->getExitCode());
        $this->assertStringContainsString('WARN', $tester->getStdout());
        $this->assertStringContainsString('passed with warnings', $tester->getStdout());
    }

    #[Test]
    public function failuresReturnExitCode2(): void
    {
        $checker = $this->createMock(HealthCheckerInterface::class);
        $checker->method('runAll')->willReturn([
            HealthCheckResult::fail('Database', DiagnosticCode::DATABASE_UNREACHABLE, 'Cannot connect.'),
        ]);

        $tester = $this->createTester($checker);
        $tester->execute([]);

        $this->assertSame(2, $tester->getExitCode());
        $this->assertStringContainsString('FAIL', $tester->getStdout());
        $this->assertStringContainsString('Health check failed', $tester->getStdout());
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
        $this->assertCount(1, $decoded);
        $this->assertSame('Database', $decoded[0]['name']);
    }

    private function createTester(HealthCheckerInterface $checker): CliTester
    {
        $handler = new HealthCheckHandler($checker);
        $definition = new HandlerCommand(
            name: 'health:check',
            description: 'Run all diagnostic health checks and report results',
            options: [
                new HandlerOption(name: 'json', mode: HandlerOptionMode::None, description: 'Output results as JSON'),
            ],
            handler: \Closure::fromCallable([$handler, 'execute']),
        );

        $container = new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed { throw new \RuntimeException("Not found: $id"); }
            public function has(string $id): bool { return false; }
        };

        return CliTester::for($definition, $container);
    }
}
