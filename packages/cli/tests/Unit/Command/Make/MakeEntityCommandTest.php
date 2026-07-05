<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command\Make;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\CLI\Handler\MakeEntityHandler;
use Waaseyaa\CLI\Provider\MakeServiceProviderA;
use Waaseyaa\CLI\Testing\CliTester;

#[CoversClass(MakeEntityHandler::class)]
final class MakeEntityCommandTest extends TestCase
{
    #[Test]
    public function it_generates_an_entity_class(): void
    {
        $tester = $this->createTester();
        $tester->execute(['Article']);

        $this->assertSame(0, $tester->getExitCode());
        $output = $tester->getStdout();
        $this->assertStringContainsString('class Article extends ContentEntityBase', $output);
        $this->assertStringContainsString('use Waaseyaa\\Entity\\ContentEntityBase;', $output);
        $this->assertStringContainsString('declare(strict_types=1);', $output);
    }

    #[Test]
    public function it_converts_snake_case_to_pascal_case(): void
    {
        $tester = $this->createTester();
        $tester->execute(['blog_post']);

        $output = $tester->getStdout();
        $this->assertStringContainsString('class BlogPost extends ContentEntityBase', $output);
    }

    #[Test]
    public function it_rejects_a_quote_breakout_payload_in_name(): void
    {
        $tester = $this->createTester();
        $tester->execute(["foo', system('touch pwned'); //"]);

        $this->assertSame(1, $tester->getExitCode());
        $this->assertStringNotContainsString('system(', $tester->getStdout());
        $this->assertStringContainsString('Invalid name', $tester->getStderr());
    }

    #[Test]
    public function it_rejects_a_path_traversal_name(): void
    {
        $tester = $this->createTester();
        $tester->execute(['../evil']);

        $this->assertSame(1, $tester->getExitCode());
    }

    #[Test]
    public function it_rejects_a_newline_injected_name(): void
    {
        $tester = $this->createTester();
        $tester->execute(["Foo\n}\nclass Evil {"]);

        $this->assertSame(1, $tester->getExitCode());
    }

    #[Test]
    public function it_rejects_a_backtick_payload(): void
    {
        $tester = $this->createTester();
        $tester->execute(['`id`']);

        $this->assertSame(1, $tester->getExitCode());
    }

    private function createTester(): CliTester
    {
        $provider = new MakeServiceProviderA();
        $definition = null;
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'make:entity') {
                $definition = $cmd;
                break;
            }
        }
        self::assertNotNull($definition);

        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                if ($id === MakeEntityHandler::class) {
                    return new MakeEntityHandler();
                }
                throw new \RuntimeException("Not found: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === MakeEntityHandler::class;
            }
        };

        return CliTester::for($definition, $container);
    }
}
