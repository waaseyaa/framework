<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Waaseyaa\CLI\Command\QueueFlushCommand;
use Waaseyaa\Queue\Storage\InMemoryFailedJobRepository;

#[CoversClass(QueueFlushCommand::class)]
final class QueueFlushCommandTest extends TestCase
{
    #[Test]
    public function flushesAllFailedJobs(): void
    {
        $repo = new InMemoryFailedJobRepository();
        $repo->record('default', 'payload-1', new \RuntimeException('Error 1'));
        $repo->record('default', 'payload-2', new \RuntimeException('Error 2'));

        $command = new QueueFlushCommand($repo);
        $tester = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Flushed 2 failed jobs', $tester->getDisplay());
        self::assertCount(0, $repo->all());
    }

    #[Test]
    public function handlesEmptyRepository(): void
    {
        $repo = new InMemoryFailedJobRepository();
        $command = new QueueFlushCommand($repo);
        $tester = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No failed jobs to flush', $tester->getDisplay());
    }
}
