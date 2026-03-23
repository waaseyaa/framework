<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\Queue\FailedJobRepositoryInterface;

#[AsCommand(
    name: 'queue:flush',
    description: 'Remove all failed queue jobs',
)]
final class QueueFlushCommand extends Command
{
    public function __construct(
        private readonly FailedJobRepositoryInterface $failedJobRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = count($this->failedJobRepository->all());

        $this->failedJobRepository->flush();

        if ($count === 0) {
            $output->writeln('No failed jobs to flush.');
        } else {
            $label = $count === 1 ? 'job' : 'jobs';
            $output->writeln("Flushed <info>{$count}</info> failed {$label}.");
        }

        return self::SUCCESS;
    }
}
