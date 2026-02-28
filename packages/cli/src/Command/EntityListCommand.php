<?php

declare(strict_types=1);

namespace Aurora\CLI\Command;

use Aurora\Entity\EntityTypeManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'entity:list',
    description: 'List entities of a given type',
)]
class EntityListCommand extends Command
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('entity_type', InputArgument::REQUIRED, 'The entity type ID')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum number of entities to list', '25');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $entityType = $input->getArgument('entity_type');
        $limit = (int) $input->getOption('limit');

        $storage = $this->entityTypeManager->getStorage($entityType);
        $ids = $storage->getQuery()
            ->accessCheck(false)
            ->range(0, $limit)
            ->execute();

        if ($ids === []) {
            $output->writeln('No entities found.');

            return Command::SUCCESS;
        }

        $entities = $storage->loadMultiple($ids);

        $table = new Table($output);
        $table->setHeaders(['ID', 'Label']);

        foreach ($entities as $entity) {
            $table->addRow([(string) $entity->id(), $entity->label()]);
        }

        $table->render();

        return Command::SUCCESS;
    }
}
