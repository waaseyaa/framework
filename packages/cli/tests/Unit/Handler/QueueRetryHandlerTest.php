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
        self::assertStringContainsString("Failed to retry job [{$jobId}]: dispatch exploded", $tester->getStdout());
        self::assertTrue($repo->claimForRetry($jobId), 'A failed dispatch must release the retry claim.');
    }

    #[Test]
    public function doesNotDispatchWhenAnotherCallerOwnsTheClaim(): void
    {
        $repo = new InMemoryFailedJobRepository();
        $jobId = $repo->record('default', serialize(new SuccessfulJob()), new \RuntimeException('Error'));
        self::assertTrue($repo->claimForRetry($jobId));
        $queue = new class implements QueueInterface {
            public int $dispatches = 0;
            public function dispatch(object $message): void { $this->dispatches++; }
        };
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer($repo, $queue));

        $tester->executeMap(['id' => $jobId]);

        self::assertSame(1, $tester->getExitCode());
        self::assertSame(0, $queue->dispatches);
        self::assertStringContainsString('already being retried', $tester->getStdout());
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
        $badId = $repo->record('default', 'not-a-valid-serialized-payload', new \RuntimeException('Error 1'));
        $goodId = $repo->record('default', serialize(new SuccessfulJob()), new \RuntimeException('Error 2'));

        $queue = new SyncQueue();
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer($repo, $queue));

        $tester->executeMap(['id' => 'all']);

        self::assertSame(1, $tester->getExitCode());
        self::assertNull($repo->find($goodId), 'Successfully dispatched job should be removed.');
        self::assertNotNull($repo->find($badId), 'Corrupt-payload job should be preserved.');
    }

    #[Test]
    public function retryAllContinuesPastThrowingDispatch(): void
    {
        $repo = new InMemoryFailedJobRepository();
        $failingId = $repo->record('default', serialize(new SuccessfulJob()), new \RuntimeException('Error 1'));
        $succeedingId = $repo->record('default', serialize(new SuccessfulJob()), new \RuntimeException('Error 2'));

        $queue = new class implements QueueInterface {
            private int $calls = 0;

            public function dispatch(object $message): void
            {
                if (++$this->calls === 1) {
                    throw new \RuntimeException('dispatch exploded');
                }
            }
        };
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer($repo, $queue));

        $tester->executeMap(['id' => 'all']);

        self::assertSame(1, $tester->getExitCode());
        self::assertNotNull($repo->find($failingId), 'Job whose dispatch threw must be preserved.');
        self::assertNull($repo->find($succeedingId), 'Job dispatched after the failure must still be removed.');
        self::assertStringContainsString('Retried 1 failed job(s).', $tester->getStdout());
        self::assertStringContainsString('1 failed job(s) could not be re-dispatched', $tester->getStdout());
    }

    #[Test]
    public function retryAllWithNoFailedJobsReportsNothingToRetry(): void
    {
        $repo = new InMemoryFailedJobRepository();
        $queue = new SyncQueue();
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer($repo, $queue));

        $tester->executeMap(['id' => 'all']);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('No failed jobs to retry.', $tester->getStdout());
    }
}
