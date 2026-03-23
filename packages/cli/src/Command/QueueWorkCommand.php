<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\Queue\Worker\Worker;
use Waaseyaa\Queue\Worker\WorkerOptions;

#[AsCommand(
    name: 'queue:work',
    description: 'Process jobs from the queue',
)]
final class QueueWorkCommand extends Command
{
    public function __construct(
        private readonly Worker $worker,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('queue', InputArgument::OPTIONAL, 'The queue to process', 'default');
        $this->addOption('sleep', null, InputOption::VALUE_REQUIRED, 'Seconds to sleep when no jobs available', '3');
        $this->addOption('tries', null, InputOption::VALUE_REQUIRED, 'Max attempts before failing a job', '3');
        $this->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Seconds a job may run', '60');
        $this->addOption('max-jobs', null, InputOption::VALUE_REQUIRED, 'Process N jobs then exit (0 = unlimited)', '0');
        $this->addOption('max-time', null, InputOption::VALUE_REQUIRED, 'Run for N seconds then exit (0 = unlimited)', '0');
        $this->addOption('memory', null, InputOption::VALUE_REQUIRED, 'Memory limit in MB', '128');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $queue = $input->getArgument('queue');
        $options = new WorkerOptions(
            sleep: (int) $input->getOption('sleep'),
            maxJobs: (int) $input->getOption('max-jobs'),
            maxTime: (int) $input->getOption('max-time'),
            memoryLimit: (int) $input->getOption('memory'),
            timeout: (int) $input->getOption('timeout'),
            maxTries: (int) $input->getOption('tries'),
        );

        $output->writeln("Processing jobs from the <info>{$queue}</info> queue.");

        $processed = $this->worker->run($queue, $options);

        $output->writeln("Processed <info>{$processed}</info> jobs.");

        return self::SUCCESS;
    }
}
