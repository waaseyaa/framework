<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Waaseyaa\CLI\Command\QueueRetryCommand;
use Waaseyaa\Queue\SyncQueue;
use Waaseyaa\Queue\Storage\InMemoryFailedJobRepository;
use Waaseyaa\Queue\Tests\Unit\Fixtures\SuccessfulJob;

#[CoversClass(QueueRetryCommand::class)]
final class QueueRetryCommandTest extends TestCase
{
    #[Test]
    public function retriesSingleFailedJob(): void
    {
        $repo = new InMemoryFailedJobRepository();
        $id = $repo->record('default', serialize(new SuccessfulJob()), new \RuntimeException('Error'));

        $queue = new SyncQueue();
        $command = new QueueRetryCommand($repo, $queue);
        $tester = new CommandTester($command);

        $tester->execute(['id' => $id]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString("Retrying failed job [{$id}]", $tester->getDisplay());
        self::assertNull($repo->find($id));
    }

    #[Test]
    public function failsForMissingJob(): void
    {
        $repo = new InMemoryFailedJobRepository();
        $queue = new SyncQueue();
        $command = new QueueRetryCommand($repo, $queue);
        $tester = new CommandTester($command);

        $tester->execute(['id' => '999']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('not found', $tester->getDisplay());
    }

    #[Test]
    public function retriesAllFailedJobs(): void
    {
        $repo = new InMemoryFailedJobRepository();
        $repo->record('default', serialize(new SuccessfulJob()), new \RuntimeException('Error 1'));
        $repo->record('default', serialize(new SuccessfulJob()), new \RuntimeException('Error 2'));

        $queue = new SyncQueue();
        $command = new QueueRetryCommand($repo, $queue);
        $tester = new CommandTester($command);

        $tester->execute(['id' => 'all']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Retried 2 failed job(s)', $tester->getDisplay());
    }
}
