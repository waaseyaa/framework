<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\Config\ConfigManagerInterface;

#[AsCommand(
    name: 'config:export',
    description: 'Export active configuration to the sync directory',
)]
class ConfigExportCommand extends Command
{
    public function __construct(
        private readonly ConfigManagerInterface $configManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->configManager->export();

        $configs = $this->configManager->getActiveStorage()->listAll();
        $count = count($configs);

        $output->writeln(sprintf('Configuration exported. Active storage contains %d items.', $count));

        return Command::SUCCESS;
    }
}
