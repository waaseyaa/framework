<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Waaseyaa\CLI\Command\QueueFailedCommand;
use Waaseyaa\Queue\Storage\InMemoryFailedJobRepository;

#[CoversClass(QueueFailedCommand::class)]
final class QueueFailedCommandTest extends TestCase
{
    #[Test]
    public function displaysNoFailedJobs(): void
    {
        $repo = new InMemoryFailedJobRepository();
        $command = new QueueFailedCommand($repo);
        $tester = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No failed jobs', $tester->getDisplay());
    }

    #[Test]
    public function listsFailedJobs(): void
    {
        $repo = new InMemoryFailedJobRepository();
        $repo->record('default', 'payload', new \RuntimeException('Something broke'));

        $command = new QueueFailedCommand($repo);
        $tester = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('1 failed job', $display);
        self::assertStringContainsString('Queue: default', $display);
        self::assertStringContainsString('Something broke', $display);
    }
}
