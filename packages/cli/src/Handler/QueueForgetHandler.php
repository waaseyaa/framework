<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Queue\FailedJobRepositoryInterface;

/** @api */
final class QueueForgetHandler
{
    public function __construct(
        private readonly FailedJobRepositoryInterface $failedJobRepository,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $id = (string) $io->argument('id');
        if ($this->failedJobRepository->find($id) === null) {
            $io->writeln("Failed job [{$id}] not found.");

            return 1;
        }

        $this->failedJobRepository->forget($id);
        $io->writeln("Forgot failed job [{$id}].");

        return 0;
    }
}
