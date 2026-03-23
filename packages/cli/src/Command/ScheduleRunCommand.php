<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\Scheduler\ScheduleRunner;

#[AsCommand(
    name: 'schedule:run',
    description: 'Run due scheduled tasks',
)]
final class ScheduleRunCommand extends Command
{
    public function __construct(
        private readonly ScheduleRunner $runner,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = new \DateTimeImmutable();
        $result = $this->runner->run($now);

        if ($result->count === 0) {
            $output->writeln('No scheduled tasks are due.');

            return self::SUCCESS;
        }

        foreach ($result->taskNames as $name) {
            $output->writeln("  Ran: <info>{$name}</info>");
        }

        $label = $result->count === 1 ? 'task' : 'tasks';
        $output->writeln("Executed <info>{$result->count}</info> scheduled {$label}.");

        return self::SUCCESS;
    }
}
