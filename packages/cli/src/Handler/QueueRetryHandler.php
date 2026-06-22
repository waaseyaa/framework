<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Queue\FailedJobRepositoryInterface;
use Waaseyaa\Queue\QueueInterface;

/**
 * @api
 */
final class QueueRetryHandler
{
    public function __construct(
        private readonly FailedJobRepositoryInterface $failedJobRepository,
        private readonly QueueInterface $queue,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        /** @var string $id */
        $id = $io->argument('id');

        if ($id === 'all') {
            return $this->retryAll($io);
        }

        $record = $this->failedJobRepository->retry($id);
        if ($record === null) {
            $io->writeln("Failed job [{$id}] not found.");

            return 1;
        }

        $message = @unserialize($record['payload']);
        if ($message === false || !is_object($message)) {
            $io->writeln("Failed job [{$id}] has corrupt payload and cannot be retried.");

            return 1;
        }

        $this->queue->dispatch($message);
        $io->writeln("Retrying failed job [{$id}].");

        return 0;
    }

    private function retryAll(SymfonyCommandIO $io): int
    {
        $all = $this->failedJobRepository->all();
        if ($all === []) {
            $io->writeln('No failed jobs to retry.');

            return 0;
        }

        $retried = 0;
        foreach ($all as $record) {
            $retrieved = $this->failedJobRepository->retry($record['id']);
            if ($retrieved === null) {
                continue;
            }

            $message = @unserialize($retrieved['payload']);
            if ($message === false || !is_object($message)) {
                $io->writeln("Skipping job [{$record['id']}] — corrupt payload.");
                continue;
            }

            $this->queue->dispatch($message);
            $retried++;
        }

        $io->writeln("Retried {$retried} failed job(s).");

        return 0;
    }
}
