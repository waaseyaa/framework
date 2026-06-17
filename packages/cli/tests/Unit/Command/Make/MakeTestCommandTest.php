<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command\Make;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\CLI\Handler\MakeTestHandler;
use Waaseyaa\CLI\Provider\MakeServiceProviderB;
use Waaseyaa\CLI\Testing\CliTester;

#[CoversClass(MakeTestHandler::class)]
final class MakeTestCommandTest extends TestCase
{
    #[Test]
    public function it_generates_an_integration_test_by_default(): void
    {
        $tester = $this->createTester();
        $tester->execute(['NodeRepositoryTest']);

        $this->assertSame(0, $tester->getExitCode());
        $output = $tester->getStdout();
        $this->assertStringContainsString('class NodeRepositoryTest extends TestCase', $output);
        $this->assertStringContainsString('namespace App\\Tests\\Integration;', $output);
        $this->assertStringContainsString('declare(strict_types=1);', $output);
    }

    #[Test]
    public function it_generates_a_unit_test_with_flag(): void
    {
        $tester = $this->createTester();
        $tester->execute(['NodeRepositoryTest', '--unit']);

        $output = $tester->getStdout();
        $this->assertStringContainsString('namespace App\\Tests\\Unit;', $output);
    }

    private function createTester(): CliTester
    {
        $provider = new MakeServiceProviderB();
        $definition = null;
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'make:test') {
                $definition = $cmd;
                break;
            }
        }
        self::assertNotNull($definition);

        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                if ($id === MakeTestHandler::class) {
                    return new MakeTestHandler();
                }
                throw new \RuntimeException("Not found: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === MakeTestHandler::class;
            }
        };

        return CliTester::for($definition, $container);
    }
}
