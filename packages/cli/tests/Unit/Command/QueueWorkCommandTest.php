<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Waaseyaa\CLI\Command\QueueWorkCommand;
use Waaseyaa\Queue\Handler\JobHandler;
use Waaseyaa\Queue\Storage\InMemoryFailedJobRepository;
use Waaseyaa\Queue\Tests\Unit\Fixtures\SuccessfulJob;
use Waaseyaa\Queue\Transport\InMemoryTransport;
use Waaseyaa\Queue\Worker\Worker;

#[CoversClass(QueueWorkCommand::class)]
final class QueueWorkCommandTest extends TestCase
{
    #[Test]
    public function processesJobsFromQueue(): void
    {
        $transport = new InMemoryTransport();
        $transport->push('default', serialize(new SuccessfulJob()));

        $worker = new Worker($transport, new InMemoryFailedJobRepository(), [new JobHandler()]);
        $command = new QueueWorkCommand($worker);
        $tester = new CommandTester($command);

        SuccessfulJob::reset();
        $tester->execute(['--max-jobs' => '1']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Processed 1 jobs', $tester->getDisplay());
        self::assertSame(1, SuccessfulJob::$handleCount);
    }

    #[Test]
    public function exitsGracefullyWhenNoJobs(): void
    {
        $transport = new InMemoryTransport();
        $worker = new Worker($transport, new InMemoryFailedJobRepository(), [new JobHandler()]);
        $command = new QueueWorkCommand($worker);
        $tester = new CommandTester($command);

        $tester->execute(['--max-jobs' => '1', '--max-time' => '1']);

        self::assertSame(0, $tester->getStatusCode());
    }
}
