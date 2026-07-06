<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Scheduler\ScheduleRunner;

/**
 * @api
 */
final class ScheduleRunHandler
{
    public function __construct(
        private readonly ScheduleRunner $runner,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $now = new \DateTimeImmutable();
        $result = $this->runner->run($now);

        if ($result->count === 0 && $result->failedCount === 0) {
            $io->writeln('No scheduled tasks are due.');

            return 0;
        }

        foreach ($result->taskNames as $name) {
            $io->writeln("  Ran: <info>{$name}</info>");
        }

        if ($result->count > 0) {
            $label = $result->count === 1 ? 'task' : 'tasks';
            $io->writeln("Executed <info>{$result->count}</info> scheduled {$label}.");
        }

        if ($result->failedCount > 0) {
            foreach ($result->failedTaskNames as $name) {
                $io->writeln("  Failed: <error>{$name}</error>");
            }

            $failedLabel = $result->failedCount === 1 ? 'task' : 'tasks';
            $io->writeln("<error>{$result->failedCount} scheduled {$failedLabel} failed.</error>");

            return 1;
        }

        return 0;
    }
}
