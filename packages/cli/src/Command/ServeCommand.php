<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'serve',
    description: 'Start the PHP development server',
)]
final class ServeCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('host', null, InputOption::VALUE_OPTIONAL, 'The host address', '127.0.0.1')
            ->addOption('port', 'p', InputOption::VALUE_OPTIONAL, 'The port', '8080');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $host = $input->getOption('host');
        $port = $input->getOption('port');

        $output->writeln(sprintf('<info>Waaseyaa development server started:</info> http://%s:%s', $host, $port));
        $output->writeln('<comment>Press Ctrl+C to stop.</comment>');

        $process = proc_open(
            [PHP_BINARY, '-S', "{$host}:{$port}", '-t', 'public'],
            [STDIN, STDOUT, STDERR],
            $pipes,
        );

        if ($process === false) {
            $output->writeln('<error>Failed to start the development server.</error>');
            return self::FAILURE;
        }

        return proc_close($process);
    }
}
