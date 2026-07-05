<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command\Make;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\CLI\Handler\MakeJobHandler;
use Waaseyaa\CLI\Provider\MakeServiceProviderA;
use Waaseyaa\CLI\Testing\CliTester;

#[CoversClass(MakeJobHandler::class)]
final class MakeJobCommandTest extends TestCase
{
    #[Test]
    public function it_generates_a_job_class(): void
    {
        $tester = $this->createTester();
        $tester->execute(['ProcessUpload']);

        $this->assertSame(0, $tester->getExitCode());
        $output = $tester->getStdout();
        $this->assertStringContainsString('class ProcessUpload extends Job', $output);
        $this->assertStringContainsString('use Waaseyaa\\Queue\\Job\\Job;', $output);
        $this->assertStringContainsString('public function handle(): void', $output);
        $this->assertStringContainsString('declare(strict_types=1);', $output);
    }

    #[Test]
    public function it_converts_snake_case_name(): void
    {
        $tester = $this->createTester();
        $tester->execute(['process_upload']);

        $output = $tester->getStdout();
        $this->assertStringContainsString('class ProcessUpload extends Job', $output);
    }

    #[Test]
    public function it_rejects_a_quote_breakout_payload_in_name(): void
    {
        $tester = $this->createTester();
        $tester->execute(["foo', system('touch pwned'); //"]);

        $this->assertSame(1, $tester->getExitCode());
        $this->assertStringNotContainsString('system(', $tester->getStdout());
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

    private function createTester(): CliTester
    {
        $provider = new MakeServiceProviderA();
        $definition = null;
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'make:job') {
                $definition = $cmd;
                break;
            }
        }
        self::assertNotNull($definition);

        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                if ($id === MakeJobHandler::class) {
                    return new MakeJobHandler();
                }
                throw new \RuntimeException("Not found: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === MakeJobHandler::class;
            }
        };

        return CliTester::for($definition, $container);
    }
}
