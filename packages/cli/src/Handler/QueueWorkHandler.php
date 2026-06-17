<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Queue\Worker\Worker;
use Waaseyaa\Queue\Worker\WorkerOptions;

/**
 * @api
 */
final class QueueWorkHandler
{
    public function __construct(
        private readonly Worker $worker,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        /** @var string $queue */
        $queue = $io->argument('queue') ?? 'default';

        $options = new WorkerOptions(
            sleep: (int) ($io->option('sleep') ?? 3),
            maxJobs: (int) ($io->option('max-jobs') ?? 0),
            maxTime: (int) ($io->option('max-time') ?? 0),
            memoryLimit: (int) ($io->option('memory') ?? 128),
            timeout: (int) ($io->option('timeout') ?? 60),
            maxTries: (int) ($io->option('tries') ?? 3),
        );

        $io->writeln("Processing jobs from the {$queue} queue.");

        $processed = $this->worker->run($queue, $options);

        $io->writeln("Processed {$processed} jobs.");

        return 0;
    }
}
