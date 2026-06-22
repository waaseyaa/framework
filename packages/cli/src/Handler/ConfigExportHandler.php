<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Config\ConfigManagerInterface;

/**
 * @api
 */
final class ConfigExportHandler
{
    public function __construct(
        private readonly ConfigManagerInterface $configManager,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $this->configManager->export();

        $configs = $this->configManager->getActiveStorage()->listAll();
        $count = count($configs);

        $io->writeln(sprintf('Configuration exported. Active storage contains %d items.', $count));

        return 0;
    }
}
