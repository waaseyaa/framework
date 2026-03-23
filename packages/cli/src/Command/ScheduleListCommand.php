<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\Scheduler\ScheduleInterface;

#[AsCommand(
    name: 'schedule:list',
    description: 'List all registered scheduled tasks',
)]
final class ScheduleListCommand extends Command
{
    public function __construct(
        private readonly ScheduleInterface $schedule,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tasks = $this->schedule->tasks();

        if ($tasks === []) {
            $output->writeln('No scheduled tasks registered.');

            return self::SUCCESS;
        }

        $now = new \DateTimeImmutable();
        $output->writeln(sprintf('Found <info>%d</info> scheduled task(s):', count($tasks)));
        $output->writeln('');

        foreach ($tasks as $task) {
            $nextRun = $task->getNextRunDate($now)->format('Y-m-d H:i:s');
            $overlap = $task->preventOverlap ? ' [no-overlap]' : '';
            $desc = $task->description !== null ? " — {$task->description}" : '';

            $output->writeln(sprintf(
                '  <info>%s</info>  %s  Next: %s%s%s',
                $task->name,
                $task->expression,
                $nextRun,
                $overlap,
                $desc,
            ));
        }

        return self::SUCCESS;
    }
}
