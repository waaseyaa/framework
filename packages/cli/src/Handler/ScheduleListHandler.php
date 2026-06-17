<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Scheduler\ScheduledTask;
use Waaseyaa\Scheduler\ScheduleEntriesInterface;
use Waaseyaa\Scheduler\ScheduleInterface;

/**
 * @api
 */
final class ScheduleListHandler
{
    public function __construct(
        private readonly ScheduleInterface $schedule,
        private readonly ?PackageManifest $manifest = null,
        /** @var array<string, mixed> */
        private readonly array $config = [],
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        // When manifest is available, show grouped output by owning class (FR-008).
        if ($this->manifest !== null && $this->manifest->scheduleEntries !== []) {
            return $this->executeGrouped($io);
        }

        // Fallback: flat list (no manifest, e.g. in tests without manifest injection).
        return $this->executeFlat($io);
    }

    private function executeGrouped(SymfonyCommandIO $io): int
    {
        assert($this->manifest !== null);

        /** @var list<string> $disabledEntries */
        $disabledEntries = $this->config['schedule']['disabled_entries'] ?? [];

        $totalActive = count($this->schedule->tasks());
        $totalEntries = count($this->manifest->scheduleEntries);

        if ($totalActive === 0 && $totalEntries === 0) {
            $io->writeln('No scheduled tasks registered.');

            return 0;
        }

        $io->writeln(sprintf(
            'Found <info>%d</info> scheduled task(s) across <info>%d</info> entries class(es):',
            $totalActive,
            $totalEntries,
        ));
        $io->writeln('');

        $now = new \DateTimeImmutable();

        foreach ($this->manifest->scheduleEntries as $fqcn) {
            $isDisabled = in_array($fqcn, $disabledEntries, true);
            $prefix = $isDisabled ? '[disabled] ' : '';

            $io->writeln(sprintf('<comment>%s%s</comment>', $prefix, $fqcn));

            if ($isDisabled) {
                $io->writeln('  (not registered — disabled by schedule.disabled_entries)');
                $io->writeln('');
                continue;
            }

            // Use a fresh register() call (read-only probe, not the live schedule)
            // to discover which task identities each class contributes (option 3).
            $ownedTasks = $this->probeEntriesTasks($fqcn);

            if ($ownedTasks === []) {
                $io->writeln('  (no tasks registered)');
                $io->writeln('');
                continue;
            }

            foreach ($ownedTasks as $identity => $task) {
                $nextRun = $task->getNextRunDate($now)->format('Y-m-d H:i:s');
                $overlap = $task->preventOverlap ? ' [no-overlap]' : '';
                $desc = $task->description !== null ? " — {$task->description}" : '';

                $io->writeln(sprintf(
                    '  <info>%s</info>  %s  Next: %s%s%s',
                    $identity,
                    $task->expression,
                    $nextRun,
                    $overlap,
                    $desc,
                ));
            }

            $io->writeln('');
        }

        return 0;
    }

    /**
     * Probe a ScheduleEntriesInterface class by calling register() on a fresh
     * Schedule instance, returning the map of task identity → ScheduledTask.
     *
     * Uses a real Schedule (not an anonymous collector) so that implementations
     * that type-check for the concrete class (e.g. AgentScheduleEntries) work.
     * This avoids adding an ownerClass property to ScheduledTask (C-005).
     *
     * @return array<string, ScheduledTask>
     */
    private function probeEntriesTasks(string $fqcn): array
    {
        if (!class_exists($fqcn)) {
            return [];
        }

        try {
            $ref = new \ReflectionClass($fqcn);
            $constructor = $ref->getConstructor();

            // Only instantiate entries with all-optional/zero required params here.
            // Entries that require container-provided dependencies are already
            // registered in the live schedule at boot — match them by name instead.
            $requiredParams = 0;
            if ($constructor !== null) {
                foreach ($constructor->getParameters() as $param) {
                    if (!$param->isOptional() && !$param->isDefaultValueAvailable()) {
                        ++$requiredParams;
                    }
                }
            }

            if ($requiredParams > 0) {
                return $this->matchLiveTasksToEntries($fqcn);
            }

            /** @var ScheduleEntriesInterface $instance */
            $instance = $ref->newInstance();

            // Use a real Schedule so implementations that type-check for it pass.
            $probeSchedule = new \Waaseyaa\Scheduler\Schedule();
            $instance->register($probeSchedule);

            $collected = [];
            foreach ($probeSchedule->tasks() as $task) {
                $collected[$task->name] = $task;
            }

            return $collected;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * For entries with constructor dependencies (already registered at boot),
     * match their task names against the live schedule tasks.
     *
     * This relies on name-collision being the only way to map entries → tasks
     * when option 3 cannot instantiate the class without the container.
     *
     * @return array<string, ScheduledTask>
     */
    private function matchLiveTasksToEntries(string $fqcn): array
    {
        // Use a sibling zero-arg probe class at same namespace level to get
        // the task names this entries class contributes — not possible without
        // the container. Fall back to showing all live tasks under this class
        // header if it is the only non-zero-arg class in the manifest.
        $liveTasks = [];
        foreach ($this->schedule->tasks() as $task) {
            $liveTasks[$task->name] = $task;
        }

        // If there's only one entries class with deps, attribute all live tasks to it.
        $depsCount = 0;
        assert($this->manifest !== null);
        foreach ($this->manifest->scheduleEntries as $entry) {
            if (!class_exists($entry)) {
                continue;
            }

            $ref = new \ReflectionClass($entry);
            $ctor = $ref->getConstructor();

            if ($ctor !== null && $ctor->getNumberOfParameters() > 0) {
                ++$depsCount;
            }
        }

        if ($depsCount === 1) {
            return $liveTasks;
        }

        // Multiple entries with deps: cannot reliably attribute — return empty
        // and let the caller show "no tasks registered" rather than misattribute.
        return [];
    }

    private function executeFlat(SymfonyCommandIO $io): int
    {
        $tasks = $this->schedule->tasks();

        if ($tasks === []) {
            $io->writeln('No scheduled tasks registered.');

            return 0;
        }

        $now = new \DateTimeImmutable();
        $io->writeln(sprintf('Found <info>%d</info> scheduled task(s):', count($tasks)));
        $io->writeln('');

        foreach ($tasks as $task) {
            $nextRun = $task->getNextRunDate($now)->format('Y-m-d H:i:s');
            $overlap = $task->preventOverlap ? ' [no-overlap]' : '';
            $desc = $task->description !== null ? " — {$task->description}" : '';

            $io->writeln(sprintf(
                '  <info>%s</info>  %s  Next: %s%s%s',
                $task->name,
                $task->expression,
                $nextRun,
                $overlap,
                $desc,
            ));
        }

        return 0;
    }
}
