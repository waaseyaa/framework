<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Queue\FailedJobRepositoryInterface;
use Waaseyaa\Queue\PersistentPayloadReplayInterface;
use Waaseyaa\Queue\QueueInterface;
use Waaseyaa\Queue\Security\SignedQueuePayload;

/**
 * @api
 */
final class QueueRetryHandler
{
    public function __construct(
        private readonly FailedJobRepositoryInterface $failedJobRepository,
        private readonly QueueInterface $queue,
        private readonly SignedQueuePayload $payloadSigner,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        /** @var string $id */
        $id = $io->argument('id');

        if ($id === 'all') {
            return $this->retryAll($io);
        }

        $record = $this->failedJobRepository->find($id);
        if ($record === null) {
            $io->writeln("Failed job [{$id}] not found.");

            return 1;
        }

        try {
            $message = @unserialize($this->payloadSigner->open($record['payload']));
        } catch (\RuntimeException) {
            $message = false;
        }
        if ($message === false || !is_object($message)) {
            $io->writeln("Failed job [{$id}] has corrupt payload and cannot be retried.");

            return 1;
        }

        if (!$this->failedJobRepository->claimForRetry($id)) {
            $io->writeln("Failed job [{$id}] is already being retried.");

            return 1;
        }

        try {
            $this->redispatch($record, $message);
        } catch (\Throwable $e) {
            $this->failedJobRepository->releaseRetryClaim($id);
            $io->writeln("Failed to retry job [{$id}]: {$e->getMessage()}");

            return 1;
        }

        $this->failedJobRepository->forget($id);
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
        $failed = 0;
        foreach ($all as $record) {
            try {
                $message = @unserialize($this->payloadSigner->open($record['payload']));
            } catch (\RuntimeException) {
                $message = false;
            }
            if ($message === false || !is_object($message)) {
                $io->writeln("Skipping job [{$record['id']}]: corrupt payload.");
                $failed++;
                continue;
            }

            if (!$this->failedJobRepository->claimForRetry($record['id'])) {
                $io->writeln("Skipping job [{$record['id']}]: already being retried.");
                $failed++;
                continue;
            }

            try {
                $this->redispatch($record, $message);
            } catch (\Throwable $e) {
                $this->failedJobRepository->releaseRetryClaim($record['id']);
                $io->writeln("Failed to retry job [{$record['id']}]: {$e->getMessage()}");
                $failed++;
                continue;
            }

            $this->failedJobRepository->forget($record['id']);
            $retried++;
        }

        $io->writeln("Retried {$retried} failed job(s).");
        if ($failed > 0) {
            $io->writeln("{$failed} failed job(s) could not be re-dispatched and remain queued for retry.");
        }

        return $failed > 0 ? 1 : 0;
    }

    /** @param array{id: string, queue: string, payload: string, exception: string, failed_at: string} $record */
    private function redispatch(array $record, object $message): void
    {
        if ($this->queue instanceof PersistentPayloadReplayInterface) {
            $this->queue->replaySignedPayload($record['queue'], $record['payload']);

            return;
        }

        $this->queue->dispatch($message);
    }
}
