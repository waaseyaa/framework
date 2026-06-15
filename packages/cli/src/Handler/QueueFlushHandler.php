<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\CliIO;
use Waaseyaa\Queue\FailedJobRepositoryInterface;

/**
 * @api
 */
final class QueueFlushHandler
{
    public function __construct(
        private readonly FailedJobRepositoryInterface $failedJobRepository,
    ) {}

    public function execute(CliIO $io): int
    {
        $count = count($this->failedJobRepository->all());

        if ($count === 0) {
            $io->writeln('No failed jobs to flush.');

            return 0;
        }

        // D-34: flushing permanently deletes every failed job. Guard it with the
        // same confirmation contract as config:reset — preview the count, refuse
        // in non-interactive mode without --yes, and prompt on an interactive TTY.
        $label = $count === 1 ? 'job' : 'jobs';
        if (!(bool) $io->option('yes')) {
            if (!$io->isInteractive()) {
                $io->error("Refusing to flush {$count} failed {$label} without --yes in non-interactive mode.");

                return 1;
            }

            $confirmed = $io->confirm(
                sprintf('Permanently delete %d failed %s?', $count, $label),
                false,
            );
            if (!$confirmed) {
                $io->writeln('Aborted: no jobs were flushed.');

                return 0;
            }
        }

        $this->failedJobRepository->flush();
        $io->writeln("Flushed {$count} failed {$label}.");

        return 0;
    }
}
