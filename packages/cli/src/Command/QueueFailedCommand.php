<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\Queue\FailedJobRepositoryInterface;

#[AsCommand(
    name: 'queue:failed',
    description: 'List all failed queue jobs',
)]
final class QueueFailedCommand extends Command
{
    public function __construct(
        private readonly FailedJobRepositoryInterface $failedJobRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $failed = $this->failedJobRepository->all();

        if ($failed === []) {
            $output->writeln('No failed jobs.');

            return self::SUCCESS;
        }

        $output->writeln(sprintf('Found <info>%d</info> failed job(s):', count($failed)));
        $output->writeln('');

        foreach ($failed as $record) {
            $output->writeln(sprintf(
                '  [%s] Queue: %s | Failed: %s',
                $record['id'],
                $record['queue'],
                $record['failed_at'],
            ));
            $output->writeln(sprintf('        %s', $record['exception']));
        }

        return self::SUCCESS;
    }
}
