<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\CLI\Handler\MakePluginHandler;
use Waaseyaa\CLI\Provider\MakeServiceProviderB;
use Waaseyaa\CLI\Testing\CliTester;

#[CoversClass(MakePluginHandler::class)]
final class MakePluginCommandTest extends TestCase
{
    #[Test]
    public function it_generates_a_plugin_class(): void
    {
        $tester = $this->createTester();
        $tester->execute(['my_formatter']);

        $this->assertSame(0, $tester->getExitCode());
        $output = $tester->getStdout();
        $this->assertStringContainsString('#[WaaseyaaPlugin(', $output);
        $this->assertStringContainsString("id: 'my_formatter'", $output);
        $this->assertStringContainsString('class MyFormatter', $output);
        $this->assertStringContainsString('use Waaseyaa\\Plugin\\Attribute\\WaaseyaaPlugin;', $output);
        $this->assertStringContainsString('declare(strict_types=1);', $output);
    }

    private function createTester(): CliTester
    {
        $provider = new MakeServiceProviderB();
        $definition = null;
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'make:plugin') {
                $definition = $cmd;
                break;
            }
        }
        self::assertNotNull($definition);

        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                if ($id === MakePluginHandler::class) {
                    return new MakePluginHandler();
                }
                throw new \RuntimeException("Not found: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === MakePluginHandler::class;
            }
        };

        return CliTester::for($definition, $container);
    }
}
