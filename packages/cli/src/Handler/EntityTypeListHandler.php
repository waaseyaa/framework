<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * @api
 */
final class EntityTypeListHandler
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $definitions = $this->entityTypeManager->getDefinitions();

        if ($definitions === []) {
            $io->writeln('No entity types registered.');

            return 0;
        }

        $io->writeln(sprintf('%-20s %-30s %-50s %-14s %s', 'ID', 'Label', 'Class', 'Revisionable', 'Translatable'));
        $io->writeln(str_repeat('-', 120));

        foreach ($definitions as $definition) {
            $io->writeln(sprintf(
                '%-20s %-30s %-50s %-14s %s',
                $definition->id(),
                $definition->getLabel(),
                $definition->getClass(),
                $definition->isRevisionable() ? 'Yes' : 'No',
                $definition->isTranslatable() ? 'Yes' : 'No',
            ));
        }

        return 0;
    }
}
