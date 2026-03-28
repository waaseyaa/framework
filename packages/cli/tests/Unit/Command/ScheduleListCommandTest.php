<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Waaseyaa\CLI\Command\ScheduleListCommand;
use Waaseyaa\Scheduler\ScheduledTask;
use Waaseyaa\Scheduler\ScheduleInterface;

#[CoversClass(ScheduleListCommand::class)]
final class ScheduleListCommandTest extends TestCase
{
    #[Test]
    public function lists_registered_tasks(): void
    {
        $schedule = $this->makeSchedule([
            new ScheduledTask(
                name: 'cache:clear',
                expression: '0 * * * *',
                command: static fn () => null,
                description: 'Clear expired cache',
            ),
            new ScheduledTask(
                name: 'report:generate',
                expression: '0 0 * * *',
                command: static fn () => null,
                preventOverlap: true,
            ),
        ]);

        $command = new ScheduleListCommand($schedule);
        $tester = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('2 scheduled task(s)', $display);
        self::assertStringContainsString('cache:clear', $display);
        self::assertStringContainsString('0 * * * *', $display);
        self::assertStringContainsString('Clear expired cache', $display);
        self::assertStringContainsString('report:generate', $display);
        self::assertStringContainsString('[no-overlap]', $display);
    }

    #[Test]
    public function shows_empty_message_when_no_tasks(): void
    {
        $schedule = $this->makeSchedule([]);

        $command = new ScheduleListCommand($schedule);
        $tester = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No scheduled tasks registered.', $tester->getDisplay());
    }

    #[Test]
    public function returns_success_status_code(): void
    {
        $schedule = $this->makeSchedule([
            new ScheduledTask(
                name: 'heartbeat',
                expression: '* * * * *',
                command: static fn () => null,
            ),
        ]);

        $command = new ScheduleListCommand($schedule);
        $tester = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
    }

    /**
     * @param list<ScheduledTask> $tasks
     */
    private function makeSchedule(array $tasks): ScheduleInterface
    {
        return new class ($tasks) implements ScheduleInterface {
            /** @param list<ScheduledTask> $tasks */
            public function __construct(private readonly array $tasks) {}

            public function tasks(): array
            {
                return $this->tasks;
            }

            public function add(ScheduledTask $task): static
            {
                throw new \BadMethodCallException('Not implemented.');
            }
        };
    }
}
