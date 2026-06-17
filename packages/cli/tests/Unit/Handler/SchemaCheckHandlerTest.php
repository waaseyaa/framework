<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Handler\SchemaCheckHandler;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Foundation\Diagnostic\DiagnosticCode;
use Waaseyaa\Foundation\Diagnostic\HealthCheckerInterface;
use Waaseyaa\Foundation\Diagnostic\HealthCheckResult;

#[CoversClass(SchemaCheckHandler::class)]
final class SchemaCheckHandlerTest extends TestCase
{
    #[Test]
    public function noDriftReturnsSuccess(): void
    {
        $checker = $this->createMock(HealthCheckerInterface::class);
        $checker->method('checkSchemaDrift')->willReturn([
            HealthCheckResult::pass('Schema drift', 'All schemas match.'),
        ]);

        $tester = $this->createTester($checker);
        $tester->execute([]);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertStringContainsString('All schemas match', $tester->getStdout());
    }

    #[Test]
    public function driftDetectedReturnsExitCode1(): void
    {
        $checker = $this->createMock(HealthCheckerInterface::class);
        $checker->method('checkSchemaDrift')->willReturn([
            HealthCheckResult::fail(
                'Schema: node_type',
                DiagnosticCode::DATABASE_SCHEMA_DRIFT,
                'Table "node_type" has 1 column(s) with schema drift.',
                ['table' => 'node_type', 'drift' => [['column' => 'type', 'issue' => 'type mismatch']]],
            ),
        ]);

        $tester = $this->createTester($checker);
        $tester->execute([]);

        $this->assertSame(1, $tester->getExitCode());
        $this->assertStringContainsString('DRIFT', $tester->getStdout());
        $this->assertStringContainsString('type mismatch', $tester->getStdout());
    }

    #[Test]
    public function jsonOptionOutputsJson(): void
    {
        $checker = $this->createMock(HealthCheckerInterface::class);
        $checker->method('checkSchemaDrift')->willReturn([
            HealthCheckResult::pass('Schema drift', 'OK'),
        ]);

        $tester = $this->createTester($checker);
        $tester->execute(['--json']);

        $decoded = json_decode($tester->getStdout(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('pass', $decoded[0]['status']);
    }

    private function createTester(HealthCheckerInterface $checker): CliTester
    {
        $handler = new SchemaCheckHandler($checker);
        $definition = new HandlerCommand(
            name: 'schema:check',
            description: 'Detect schema drift between entity type definitions and database tables',
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
