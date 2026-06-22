<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Handler\QueueFlushHandler;
use Waaseyaa\CLI\Provider\QueueServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Queue\Storage\InMemoryFailedJobRepository;

#[CoversClass(QueueFlushHandler::class)]
final class QueueFlushHandlerTest extends TestCase
{
    private function makeDefinition(): \Waaseyaa\CLI\Command\HandlerCommand
    {
        $provider = new QueueServiceProvider();
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'queue:flush') {
                return $cmd;
            }
        }

        throw new \RuntimeException('queue:flush command definition not found');
    }

    private function makeContainer(InMemoryFailedJobRepository $repo): \Psr\Container\ContainerInterface
    {
        return new class ($repo) implements \Psr\Container\ContainerInterface {
            public function __construct(private readonly InMemoryFailedJobRepository $repo) {}

            public function get(string $id): mixed
            {
                if ($id === QueueFlushHandler::class) {
                    return new QueueFlushHandler($this->repo);
                }

                throw new \RuntimeException(sprintf('Container::get(%s) called unexpectedly', $id));
            }

            public function has(string $id): bool
            {
                return $id === QueueFlushHandler::class;
            }
        };
    }

    #[Test]
    public function flushesAllFailedJobs(): void
    {
        $repo = new InMemoryFailedJobRepository();
        $repo->record('default', 'payload-1', new \RuntimeException('Error 1'));
        $repo->record('default', 'payload-2', new \RuntimeException('Error 2'));

        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer($repo));
        $tester->execute(['--yes']);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('Flushed 2 failed jobs', $tester->getStdout());
        self::assertCount(0, $repo->all());
    }

    #[Test]
    public function refusesToFlushWithoutYesInNonInteractiveMode(): void
    {
        // D-34: a non-interactive run without --yes must refuse and leave jobs intact.
        $repo = new InMemoryFailedJobRepository();
        $repo->record('default', 'payload-1', new \RuntimeException('Error 1'));

        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer($repo));
        $tester->execute([]);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('Refusing to flush', $tester->getStderr());
        self::assertCount(1, $repo->all(), 'jobs must be untouched when the flush is refused');
    }

    #[Test]
    public function handlesEmptyRepository(): void
    {
        $repo = new InMemoryFailedJobRepository();
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer($repo));
        $tester->execute([]);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('No failed jobs to flush', $tester->getStdout());
    }
}
