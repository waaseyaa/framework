<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * Lists all registered entity type definitions.
 */
#[AsCommand(
    name: 'entity-type:list',
    description: 'List all registered entity types',
)]
final class EntityTypeListCommand extends Command
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $definitions = $this->entityTypeManager->getDefinitions();

        if ($definitions === []) {
            $output->writeln('No entity types registered.');

            return self::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['ID', 'Label', 'Class', 'Revisionable', 'Translatable']);

        foreach ($definitions as $definition) {
            $table->addRow([
                $definition->id(),
                $definition->getLabel(),
                $definition->getClass(),
                $definition->isRevisionable() ? 'Yes' : 'No',
                $definition->isTranslatable() ? 'Yes' : 'No',
            ]);
        }

        $table->render();

        return self::SUCCESS;
    }
}
