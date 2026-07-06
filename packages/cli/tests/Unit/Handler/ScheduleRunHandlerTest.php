<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Handler\ScheduleRunHandler;
use Waaseyaa\CLI\Provider\SchedulePerfServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Queue\QueueInterface;
use Waaseyaa\Scheduler\Lock\InMemoryLock;
use Waaseyaa\Scheduler\ScheduledTask;
use Waaseyaa\Scheduler\ScheduleInterface;
use Waaseyaa\Scheduler\ScheduleRunner;

#[CoversClass(ScheduleRunHandler::class)]
final class ScheduleRunHandlerTest extends TestCase
{
    private function makeDefinition(): \Waaseyaa\CLI\Command\HandlerCommand
    {
        $provider = new SchedulePerfServiceProvider();
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'schedule:run') {
                return $cmd;
            }
        }

        throw new \RuntimeException('schedule:run command definition not found');
    }

    /**
     * @param list<ScheduledTask> $tasks
     */
    private function makeContainer(array $tasks): \Psr\Container\ContainerInterface
    {
        $schedule = new class ($tasks) implements ScheduleInterface {
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

        $queue = new class implements QueueInterface {
            public function dispatch(object $message): void {}
        };

        $runner = new ScheduleRunner($schedule, $queue, new InMemoryLock());

        return new class ($runner) implements \Psr\Container\ContainerInterface {
            public function __construct(private readonly ScheduleRunner $runner) {}

            public function get(string $id): mixed
            {
                if ($id === ScheduleRunHandler::class) {
                    return new ScheduleRunHandler($this->runner);
                }

                throw new \RuntimeException(sprintf('Container::get(%s) called unexpectedly', $id));
            }

            public function has(string $id): bool
            {
                return $id === ScheduleRunHandler::class;
            }
        };
    }

    #[Test]
    public function shows_executed_task_names(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer([
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
        ]));

        $tester->executeMap([]);

        self::assertSame(0, $tester->getExitCode());
        $output = $tester->getStdout();
        self::assertStringContainsString('cache:clear', $output);
        self::assertStringContainsString('report:daily', $output);
        self::assertStringContainsString('2', $output);
        self::assertStringContainsString('scheduled tasks.', $output);
    }

    #[Test]
    public function shows_no_tasks_due_message(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer([]));

        $tester->executeMap([]);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('No scheduled tasks are due.', $tester->getStdout());
    }

    #[Test]
    public function shows_singular_label_for_one_task(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer([
            new ScheduledTask(
                name: 'heartbeat',
                expression: '* * * * *',
                command: static fn () => null,
            ),
        ]));

        $tester->executeMap([]);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('1', $tester->getStdout());
        self::assertStringContainsString('scheduled task.', $tester->getStdout());
    }

    #[Test]
    public function exits_nonzero_when_the_only_due_task_fails(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer([
            new ScheduledTask(
                name: 'broken-report',
                expression: '* * * * *',
                command: static fn () => throw new \RuntimeException('boom'),
            ),
        ]));

        $tester->executeMap([]);

        self::assertSame(1, $tester->getExitCode());
        $output = $tester->getStdout();
        self::assertStringContainsString('broken-report', $output);
        self::assertStringContainsString('scheduled task failed.', $output);
        self::assertStringNotContainsString('No scheduled tasks are due.', $output);
    }

    #[Test]
    public function exits_nonzero_and_preserves_isolation_on_mixed_success_and_failure(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer([
            new ScheduledTask(
                name: 'broken-report',
                expression: '* * * * *',
                command: static fn () => throw new \RuntimeException('boom'),
            ),
            new ScheduledTask(
                name: 'cache:clear',
                expression: '* * * * *',
                command: static fn () => null,
            ),
            new ScheduledTask(
                name: 'broken-cleanup',
                expression: '* * * * *',
                command: static fn () => throw new \RuntimeException('bang'),
            ),
        ]));

        $tester->executeMap([]);

        self::assertSame(1, $tester->getExitCode());
        $output = $tester->getStdout();
        self::assertStringContainsString('broken-report', $output);
        self::assertStringContainsString('broken-cleanup', $output);
        self::assertStringContainsString('cache:clear', $output);
        // The success summary must not be silently dropped on the failure path.
        self::assertStringContainsString('Executed', $output);
        // Plural failure label branch.
        self::assertStringContainsString('scheduled tasks failed.', $output);
    }
}
