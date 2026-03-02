<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Optimize;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'optimize:clear',
    description: 'Remove all cached optimization artifacts',
)]
class OptimizeClearCommand extends Command
{
    public function __construct(
        private readonly string $storagePath,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $frameworkPath = $this->storagePath . '/framework';

        if (!is_dir($frameworkPath)) {
            $output->writeln('No cached artifacts found.');
            return Command::SUCCESS;
        }

        $files = glob($frameworkPath . '/*.php');
        $count = 0;

        foreach ($files as $file) {
            if (unlink($file)) {
                $output->writeln(sprintf('Removed: %s', basename($file)));
                $count++;
            }
        }

        if ($count === 0) {
            $output->writeln('No cached artifacts found.');
        } else {
            $output->writeln(sprintf('%d cached artifact(s) cleared.', $count));
        }

        return Command::SUCCESS;
    }
}
