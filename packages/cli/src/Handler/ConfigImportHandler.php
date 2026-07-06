<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Config\ConfigManagerInterface;
use Waaseyaa\Config\Exception\ConfigImportFailedException;

/**
 * @api
 */
final class ConfigImportHandler
{
    public function __construct(
        private readonly ConfigManagerInterface $configManager,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        try {
            $result = $this->configManager->import();
        } catch (ConfigImportFailedException $e) {
            $io->error($e->getMessage());

            return 1;
        }

        $io->writeln(sprintf('Created: %d', count($result->created)));
        $io->writeln(sprintf('Updated: %d', count($result->updated)));
        $io->writeln(sprintf('Deleted: %d', count($result->deleted)));

        if ($result->hasErrors()) {
            foreach ($result->errors as $error) {
                $io->error(sprintf('Error: %s', $error));
            }

            return 1;
        }

        $io->writeln('Configuration imported successfully.');

        return 0;
    }
}
