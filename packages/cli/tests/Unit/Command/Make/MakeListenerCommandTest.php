<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command\Make;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\CLI\Handler\MakeListenerHandler;
use Waaseyaa\CLI\Provider\MakeServiceProviderA;
use Waaseyaa\CLI\Testing\CliTester;

#[CoversClass(MakeListenerHandler::class)]
final class MakeListenerCommandTest extends TestCase
{
    #[Test]
    public function it_generates_a_listener_with_default_event(): void
    {
        $tester = $this->createTester();
        $tester->execute(['NotifyOnPublish']);

        $this->assertSame(0, $tester->getExitCode());
        $output = $tester->getStdout();
        $this->assertStringContainsString('class NotifyOnPublish', $output);
        $this->assertStringContainsString('public function __invoke(object $event): void', $output);
        $this->assertStringContainsString('declare(strict_types=1);', $output);
    }

    #[Test]
    public function it_generates_a_listener_with_custom_event(): void
    {
        $tester = $this->createTester();
        $tester->execute(['NotifyOnPublish', '--event=Waaseyaa\\Entity\\Event\\EntityEvent']);

        $output = $tester->getStdout();
        $this->assertStringContainsString('use Waaseyaa\\Entity\\Event\\EntityEvent;', $output);
        $this->assertStringContainsString('public function __invoke(EntityEvent $event): void', $output);
    }

    #[Test]
    public function it_shows_async_hint_when_flag_is_set(): void
    {
        $tester = $this->createTester();
        $tester->execute(['NotifyOnPublish', '--async']);

        $output = $tester->getStdout();
        $this->assertStringContainsString('async dispatch', $output);
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
    public function it_rejects_a_breakout_payload_in_event(): void
    {
        $tester = $this->createTester();
        // Without a backslash this lands raw in the `__invoke(... $event)`
        // type-hint position — a closing paren + brace would splice
        // arbitrary top-level PHP into the generated listener file.
        $tester->execute(['NotifyOnPublish', "--event=Foo) { system('touch pwned'); } //"]);

        $this->assertSame(1, $tester->getExitCode());
        $this->assertStringNotContainsString('system(', $tester->getStdout());
    }

    #[Test]
    public function it_rejects_a_breakout_payload_in_a_fully_qualified_event(): void
    {
        $tester = $this->createTester();
        // With a backslash this also lands raw inside a bare `use ...;`
        // statement above the class — a semicolon terminates it early.
        $tester->execute(['NotifyOnPublish', "--event=Foo\\Bar; system('touch pwned'); //"]);

        $this->assertSame(1, $tester->getExitCode());
        $this->assertStringNotContainsString('system(', $tester->getStdout());
    }

    #[Test]
    public function it_still_accepts_the_default_event_type(): void
    {
        $tester = $this->createTester();
        $tester->execute(['NotifyOnPublish']);

        $this->assertSame(0, $tester->getExitCode(), $tester->getStderr());
    }

    private function createTester(): CliTester
    {
        $provider = new MakeServiceProviderA();
        $definition = null;
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'make:listener') {
                $definition = $cmd;
                break;
            }
        }
        self::assertNotNull($definition);

        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                if ($id === MakeListenerHandler::class) {
                    return new MakeListenerHandler();
                }
                throw new \RuntimeException("Not found: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === MakeListenerHandler::class;
            }
        };

        return CliTester::for($definition, $container);
    }
}
