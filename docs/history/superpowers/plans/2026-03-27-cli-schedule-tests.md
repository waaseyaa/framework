# CLI Schedule Command Tests Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add unit tests for `ScheduleListCommand` and `ScheduleRunCommand` CLI commands.

**Architecture:** Two test classes using Symfony `CommandTester`, following the existing `QueueRetryCommandTest` pattern. Use real `ScheduleRunner` with `InMemoryLock` for run tests. Anonymous class stubs for `ScheduleInterface` and `QueueInterface`.

**Tech Stack:** PHP 8.4, PHPUnit 10.5, Symfony Console `CommandTester`

---

### Task 1: Create ScheduleListCommandTest

**Files:**
- Create: `packages/cli/tests/Unit/Command/ScheduleListCommandTest.php`

- [ ] **Step 1: Create the test file with all 3 tests**

```php
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
```

- [ ] **Step 2: Run tests**

Run: `./vendor/bin/phpunit packages/cli/tests/Unit/Command/ScheduleListCommandTest.php`
Expected: 3 tests, OK

- [ ] **Step 3: Commit**

```bash
git add packages/cli/tests/Unit/Command/ScheduleListCommandTest.php
git commit -m "test(#639): add ScheduleListCommand tests"
```

---

### Task 2: Create ScheduleRunCommandTest

**Files:**
- Create: `packages/cli/tests/Unit/Command/ScheduleRunCommandTest.php`

- [ ] **Step 1: Create the test file with all 3 tests**

```php
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
```

- [ ] **Step 2: Run tests**

Run: `./vendor/bin/phpunit packages/cli/tests/Unit/Command/ScheduleRunCommandTest.php`
Expected: 3 tests, OK

- [ ] **Step 3: Commit**

```bash
git add packages/cli/tests/Unit/Command/ScheduleRunCommandTest.php
git commit -m "test(#639): add ScheduleRunCommand tests"
```

---

### Task 3: Final verification

- [ ] **Step 1: Run all CLI tests**

Run: `./vendor/bin/phpunit packages/cli/tests/`
Expected: All tests pass

- [ ] **Step 2: Run code style check**

Run: `composer cs-check`
Expected: No new violations

- [ ] **Step 3: Run static analysis**

Run: `composer phpstan`
Expected: No new errors
