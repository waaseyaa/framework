<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Waaseyaa\CLI\Command\ScheduleRunCommand;
use Waaseyaa\Queue\QueueInterface;
use Waaseyaa\Scheduler\Lock\InMemoryLock;
use Waaseyaa\Scheduler\ScheduledTask;
use Waaseyaa\Scheduler\ScheduleInterface;
use Waaseyaa\Scheduler\ScheduleRunner;

#[CoversClass(ScheduleRunCommand::class)]
final class ScheduleRunCommandTest extends TestCase
{
    #[Test]
    public function shows_executed_task_names(): void
    {
        $schedule = $this->makeSchedule([
            new ScheduledTask(
                name: 'cache:clear',
                expression: '* * * * *',
                command: static fn () => null,
            ),
            new ScheduledTask(
                name: 'report:daily',
                expression: '* * * * *',
                command: static fn () => null,
            ),
        ]);

        $runner = new ScheduleRunner($schedule, $this->makeQueue(), new InMemoryLock());
        $command = new ScheduleRunCommand($runner);
        $tester = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('cache:clear', $display);
        self::assertStringContainsString('report:daily', $display);
        self::assertStringContainsString('Executed 2 scheduled tasks.', $display);
    }

    #[Test]
    public function shows_no_tasks_due_message(): void
    {
        $schedule = $this->makeSchedule([]);

        $runner = new ScheduleRunner($schedule, $this->makeQueue(), new InMemoryLock());
        $command = new ScheduleRunCommand($runner);
        $tester = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No scheduled tasks are due.', $tester->getDisplay());
    }

    #[Test]
    public function shows_singular_label_for_one_task(): void
    {
        $schedule = $this->makeSchedule([
            new ScheduledTask(
                name: 'heartbeat',
                expression: '* * * * *',
                command: static fn () => null,
            ),
        ]);

        $runner = new ScheduleRunner($schedule, $this->makeQueue(), new InMemoryLock());
        $command = new ScheduleRunCommand($runner);
        $tester = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Executed 1 scheduled task.', $tester->getDisplay());
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

    private function makeQueue(): QueueInterface
    {
        return new class implements QueueInterface {
            public function dispatch(object $message): void {}
        };
    }
}
