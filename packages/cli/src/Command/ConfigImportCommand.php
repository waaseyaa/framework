<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\Config\ConfigManagerInterface;

#[AsCommand(
    name: 'config:import',
    description: 'Import configuration from the sync directory',
)]
class ConfigImportCommand extends Command
{
    public function __construct(
        private readonly ConfigManagerInterface $configManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->configManager->import();

        $output->writeln(sprintf('Created: %d', count($result->created)));
        $output->writeln(sprintf('Updated: %d', count($result->updated)));
        $output->writeln(sprintf('Deleted: %d', count($result->deleted)));

        if ($result->hasErrors()) {
            foreach ($result->errors as $error) {
                $output->writeln(sprintf('<error>Error: %s</error>', $error));
            }

            return Command::FAILURE;
        }

        $output->writeln('Configuration imported successfully.');

        return Command::SUCCESS;
    }
}
