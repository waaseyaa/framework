<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\Queue\FailedJobRepositoryInterface;
use Waaseyaa\Queue\QueueInterface;

#[AsCommand(
    name: 'queue:retry',
    description: 'Retry a failed queue job',
)]
final class QueueRetryCommand extends Command
{
    public function __construct(
        private readonly FailedJobRepositoryInterface $failedJobRepository,
        private readonly QueueInterface $queue,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'The failed job ID, or "all" to retry everything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getArgument('id');

        if ($id === 'all') {
            return $this->retryAll($output);
        }

        $record = $this->failedJobRepository->retry($id);
        if ($record === null) {
            $output->writeln("<error>Failed job [{$id}] not found.</error>");

            return self::FAILURE;
        }

        $message = @unserialize($record['payload']);
        if ($message === false || !is_object($message)) {
            $output->writeln("<error>Failed job [{$id}] has corrupt payload and cannot be retried.</error>");

            return self::FAILURE;
        }

        $this->queue->dispatch($message);
        $output->writeln("Retrying failed job <info>[{$id}]</info>.");

        return self::SUCCESS;
    }

    private function retryAll(OutputInterface $output): int
    {
        $all = $this->failedJobRepository->all();
        if ($all === []) {
            $output->writeln('No failed jobs to retry.');

            return self::SUCCESS;
        }

        $retried = 0;
        foreach ($all as $record) {
            $retrieved = $this->failedJobRepository->retry($record['id']);
            if ($retrieved === null) {
                continue;
            }

            $message = @unserialize($retrieved['payload']);
            if ($message === false || !is_object($message)) {
                $output->writeln("<comment>Skipping job [{$record['id']}] — corrupt payload.</comment>");
                continue;
            }

            $this->queue->dispatch($message);
            $retried++;
        }

        $output->writeln("Retried <info>{$retried}</info> failed job(s).");

        return self::SUCCESS;
    }
}
