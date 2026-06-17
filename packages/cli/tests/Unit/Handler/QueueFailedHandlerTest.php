<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Handler\QueueFailedHandler;
use Waaseyaa\CLI\Provider\QueueServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Queue\Storage\InMemoryFailedJobRepository;

#[CoversClass(QueueFailedHandler::class)]
final class QueueFailedHandlerTest extends TestCase
{
    private function makeDefinition(): \Waaseyaa\CLI\Command\HandlerCommand
    {
        $provider = new QueueServiceProvider();
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'queue:failed') {
                return $cmd;
            }
        }

        throw new \RuntimeException('queue:failed command definition not found');
    }

    private function makeContainer(InMemoryFailedJobRepository $repo): \Psr\Container\ContainerInterface
    {
        return new class ($repo) implements \Psr\Container\ContainerInterface {
            public function __construct(private readonly InMemoryFailedJobRepository $repo) {}

            public function get(string $id): mixed
            {
                if ($id === QueueFailedHandler::class) {
                    return new QueueFailedHandler($this->repo);
                }

                throw new \RuntimeException(sprintf('Container::get(%s) called unexpectedly', $id));
            }

            public function has(string $id): bool
            {
                return $id === QueueFailedHandler::class;
            }
        };
    }

    #[Test]
    public function displaysNoFailedJobs(): void
    {
        $repo = new InMemoryFailedJobRepository();
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer($repo));

        $tester->executeMap([]);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('No failed jobs', $tester->getStdout());
    }

    #[Test]
    public function listsFailedJobs(): void
    {
        $repo = new InMemoryFailedJobRepository();
        $repo->record('default', 'payload', new \RuntimeException('Something broke'));

        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer($repo));
        $tester->executeMap([]);

        self::assertSame(0, $tester->getExitCode());
        $stdout = $tester->getStdout();
        self::assertStringContainsString('1 failed job', $stdout);
        self::assertStringContainsString('Queue: default', $stdout);
        self::assertStringContainsString('Something broke', $stdout);
    }
}
