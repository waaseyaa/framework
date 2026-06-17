<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Queue\FailedJobRepositoryInterface;

/**
 * @api
 */
final class QueueFailedHandler
{
    public function __construct(
        private readonly FailedJobRepositoryInterface $failedJobRepository,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $failed = $this->failedJobRepository->all();

        if ($failed === []) {
            $io->writeln('No failed jobs.');

            return 0;
        }

        $io->writeln(sprintf('Found %d failed job(s):', count($failed)));
        $io->writeln('');

        foreach ($failed as $record) {
            $io->writeln(sprintf(
                '  [%s] Queue: %s | Failed: %s',
                $record['id'],
                $record['queue'],
                $record['failed_at'],
            ));
            $io->writeln(sprintf('        %s', $record['exception']));
        }

        return 0;
    }
}
