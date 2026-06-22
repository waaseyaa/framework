<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command\Make;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\CLI\Handler\MakePolicyHandler;
use Waaseyaa\CLI\Provider\MakeServiceProviderA;
use Waaseyaa\CLI\Testing\CliTester;

#[CoversClass(MakePolicyHandler::class)]
final class MakePolicyCommandTest extends TestCase
{
    #[Test]
    public function it_generates_a_policy_class(): void
    {
        $tester = $this->createTester();
        $tester->execute(['ContentPolicy']);

        $this->assertSame(0, $tester->getExitCode());
        $output = $tester->getStdout();
        $this->assertStringContainsString('class ContentPolicy implements AccessPolicyInterface', $output);
        $this->assertStringContainsString('use Waaseyaa\\Access\\AccessPolicyInterface;', $output);
        $this->assertStringContainsString('public function view(', $output);
        $this->assertStringContainsString('public function create(', $output);
        $this->assertStringContainsString('public function update(', $output);
        $this->assertStringContainsString('public function delete(', $output);
        $this->assertStringContainsString('declare(strict_types=1);', $output);
    }

    private function createTester(): CliTester
    {
        $provider = new MakeServiceProviderA();
        $definition = null;
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'make:policy') {
                $definition = $cmd;
                break;
            }
        }
        self::assertNotNull($definition);

        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                if ($id === MakePolicyHandler::class) {
                    return new MakePolicyHandler();
                }
                throw new \RuntimeException("Not found: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === MakePolicyHandler::class;
            }
        };

        return CliTester::for($definition, $container);
    }
}
