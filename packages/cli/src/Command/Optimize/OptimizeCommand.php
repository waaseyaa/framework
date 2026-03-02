<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Optimize;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'optimize',
    description: 'Run all optimization compilers',
)]
class OptimizeCommand extends Command
{
    /**
     * Sub-commands to run in order.
     */
    private const array SUB_COMMANDS = [
        'optimize:manifest',
        'optimize:config',
    ];

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $application = $this->getApplication();

        if ($application === null) {
            $output->writeln('Error: No application context available.');
            return Command::FAILURE;
        }

        $ranAny = false;

        foreach (self::SUB_COMMANDS as $commandName) {
            if (!$application->has($commandName)) {
                $output->writeln(sprintf('Skipping %s (not registered).', $commandName));
                continue;
            }

            $output->writeln(sprintf('Running %s...', $commandName));
            $subCommand = $application->find($commandName);
            $result = $subCommand->run(
                new ArrayInput([]),
                $output,
            );

            if ($result !== Command::SUCCESS) {
                $output->writeln(sprintf('%s failed.', $commandName));
                return Command::FAILURE;
            }

            $ranAny = true;
        }

        if (!$ranAny) {
            $output->writeln('No optimization commands are registered.');
            return Command::SUCCESS;
        }

        $output->writeln('All optimizations complete.');

        return Command::SUCCESS;
    }
}
