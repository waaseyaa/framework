<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Handler\ScheduleListHandler;
use Waaseyaa\CLI\Provider\SchedulePerfServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Scheduler\ScheduledTask;
use Waaseyaa\Scheduler\ScheduleInterface;

#[CoversClass(ScheduleListHandler::class)]
final class ScheduleListHandlerTest extends TestCase
{
    private function makeDefinition(): \Waaseyaa\CLI\Command\HandlerCommand
    {
        $provider = new SchedulePerfServiceProvider();
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'schedule:list') {
                return $cmd;
            }
        }

        throw new \RuntimeException('schedule:list command definition not found');
    }

    /**
     * @param list<ScheduledTask> $tasks
     * @param list<class-string>  $scheduleEntries
     * @param array<string,mixed> $config
     */
    private function makeContainer(array $tasks, array $scheduleEntries = [], array $config = []): \Psr\Container\ContainerInterface
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

        $manifest = $scheduleEntries !== []
            ? new PackageManifest(scheduleEntries: $scheduleEntries)
            : null;

        return new class ($schedule, $manifest, $config) implements \Psr\Container\ContainerInterface {
            public function __construct(
                private readonly ScheduleInterface $schedule,
                private readonly ?PackageManifest $manifest,
                private readonly array $config,
            ) {}

            public function get(string $id): mixed
            {
                if ($id === ScheduleListHandler::class) {
                    return new ScheduleListHandler($this->schedule, $this->manifest, $this->config);
                }

                throw new \RuntimeException(sprintf('Container::get(%s) called unexpectedly', $id));
            }

            public function has(string $id): bool
            {
                return $id === ScheduleListHandler::class;
            }
        };
    }

    #[Test]
    public function lists_registered_tasks(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer([
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
        ]));

        $tester->executeMap([]);

        self::assertSame(0, $tester->getExitCode());
        $output = $tester->getStdout();
        self::assertStringContainsString('scheduled task(s)', $output);
        self::assertStringContainsString('cache:clear', $output);
        self::assertStringContainsString('0 * * * *', $output);
        self::assertStringContainsString('Clear expired cache', $output);
        self::assertStringContainsString('report:generate', $output);
        self::assertStringContainsString('[no-overlap]', $output);
    }

    #[Test]
    public function shows_empty_message_when_no_tasks(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer([]));

        $tester->executeMap([]);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('No scheduled tasks registered.', $tester->getStdout());
    }

    #[Test]
    public function returns_success_status_code(): void
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
    }

    #[Test]
    public function groups_tasks_by_entries_class_when_manifest_provided(): void
    {
        // Use AgentScheduleEntries — has all-optional constructor, zero required params.
        $entriesFqcn = \Waaseyaa\Scheduler\Schedule\Ai\AgentScheduleEntries::class;

        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer(
            tasks: [],
            scheduleEntries: [$entriesFqcn],
        ));

        $tester->executeMap([]);

        self::assertSame(0, $tester->getExitCode());
        $output = $tester->getStdout();
        // Should show the class FQCN as a group header.
        self::assertStringContainsString($entriesFqcn, $output);
        // Should show task names that AgentScheduleEntries registers.
        self::assertStringContainsString('ai:purge-runs', $output);
        self::assertStringContainsString('ai:reap-stalled-runs', $output);
    }

    #[Test]
    public function shows_disabled_marker_for_opted_out_entries(): void
    {
        $entriesFqcn = \Waaseyaa\Scheduler\Schedule\Ai\AgentScheduleEntries::class;

        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer(
            tasks: [],
            scheduleEntries: [$entriesFqcn],
            config: ['schedule' => ['disabled_entries' => [$entriesFqcn]]],
        ));

        $tester->executeMap([]);

        self::assertSame(0, $tester->getExitCode());
        $output = $tester->getStdout();
        self::assertStringContainsString('[disabled]', $output);
        self::assertStringContainsString($entriesFqcn, $output);
        self::assertStringContainsString('disabled by schedule.disabled_entries', $output);
    }

    #[Test]
    public function flat_list_when_no_manifest(): void
    {
        // No manifest — falls back to flat list (backward compat).
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer([
            new ScheduledTask(
                name: 'cache:clear',
                expression: '0 * * * *',
                command: static fn () => null,
            ),
        ]));

        $tester->executeMap([]);

        self::assertSame(0, $tester->getExitCode());
        $output = $tester->getStdout();
        self::assertStringContainsString('cache:clear', $output);
        self::assertStringContainsString('scheduled task(s)', $output);
    }
}
