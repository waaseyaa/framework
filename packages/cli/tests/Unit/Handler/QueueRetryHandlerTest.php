<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Handler\QueueRetryHandler;
use Waaseyaa\CLI\Provider\QueueServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Queue\QueueInterface;
use Waaseyaa\Queue\Storage\InMemoryFailedJobRepository;
use Waaseyaa\Queue\SyncQueue;
use Waaseyaa\Queue\Tests\Unit\Fixtures\SuccessfulJob;

#[CoversClass(QueueRetryHandler::class)]
final class QueueRetryHandlerTest extends TestCase
{
    private function makeDefinition(): \Waaseyaa\CLI\Command\HandlerCommand
    {
        $provider = new QueueServiceProvider();
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'queue:retry') {
                return $cmd;
            }
        }

        throw new \RuntimeException('queue:retry command definition not found');
    }

    private function makeContainer(
        InMemoryFailedJobRepository $repo,
        QueueInterface $queue,
    ): \Psr\Container\ContainerInterface {
        return new class ($repo, $queue) implements \Psr\Container\ContainerInterface {
            public function __construct(
                private readonly InMemoryFailedJobRepository $repo,
                private readonly QueueInterface $queue,
            ) {}

            public function get(string $id): mixed
            {
                if ($id === QueueRetryHandler::class) {
                    return new QueueRetryHandler($this->repo, $this->queue);
                }

                throw new \RuntimeException(sprintf('Container::get(%s) called unexpectedly', $id));
            }

            public function has(string $id): bool
            {
                return $id === QueueRetryHandler::class;
            }
        };
    }

    #[Test]
    public function retriesSingleFailedJob(): void
    {
        $repo = new InMemoryFailedJobRepository();
        $jobId = $repo->record('default', serialize(new SuccessfulJob()), new \RuntimeException('Error'));

        $queue = new SyncQueue();
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer($repo, $queue));

        $tester->executeMap(['id' => $jobId]);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString("Retrying failed job [{$jobId}]", $tester->getStdout());
        self::assertNull($repo->find($jobId));
    }

    #[Test]
    public function failsForMissingJob(): void
    {
        $repo = new InMemoryFailedJobRepository();
        $queue = new SyncQueue();
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer($repo, $queue));

        $tester->executeMap(['id' => '999']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('not found', $tester->getStdout());
    }

    #[Test]
    public function retriesAllFailedJobs(): void
    {
        $repo = new InMemoryFailedJobRepository();
        $repo->record('default', serialize(new SuccessfulJob()), new \RuntimeException('Error 1'));
        $repo->record('default', serialize(new SuccessfulJob()), new \RuntimeException('Error 2'));

        $queue = new SyncQueue();
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer($repo, $queue));

        $tester->executeMap(['id' => 'all']);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('Retried 2 failed job(s)', $tester->getStdout());
    }

    #[Test]
    public function preservesFailedJobWhenDispatchThrows(): void
    {
        $repo = new InMemoryFailedJobRepository();
        $jobId = $repo->record('default', serialize(new SuccessfulJob()), new \RuntimeException('Error'));

        $queue = new class implements QueueInterface {
            public function dispatch(object $message): void
            {
                throw new \RuntimeException('dispatch exploded');
            }
        };
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer($repo, $queue));

        $tester->executeMap(['id' => $jobId]);

        self::assertSame(1, $tester->getExitCode());
        self::assertNotNull($repo->find($jobId), 'Failed job row must survive a throwing dispatch.');
    }

    #[Test]
    public function preservesFailedJobWithCorruptPayload(): void
    {
        $repo = new InMemoryFailedJobRepository();
        $jobId = $repo->record('default', 'not-a-valid-serialized-payload', new \RuntimeException('Error'));

        $queue = new SyncQueue();
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer($repo, $queue));

        $tester->executeMap(['id' => $jobId]);

        self::assertSame(1, $tester->getExitCode());
        self::assertNotNull($repo->find($jobId), 'Failed job row must survive a corrupt payload.');
    }

    #[Test]
    public function retryAllPreservesFailingJobsAndRemovesSucceedingOnes(): void
    {
        $repo = new InMemoryFailedJobRepository();
        $goodId = $repo->record('default', serialize(new SuccessfulJob()), new \RuntimeException('Error 1'));
        $badId = $repo->record('default', 'not-a-valid-serialized-payload', new \RuntimeException('Error 2'));

        $queue = new SyncQueue();
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer($repo, $queue));

        $tester->executeMap(['id' => 'all']);

        self::assertSame(1, $tester->getExitCode());
        self::assertNull($repo->find($goodId), 'Successfully dispatched job should be removed.');
        self::assertNotNull($repo->find($badId), 'Corrupt-payload job should be preserved.');
    }
}
