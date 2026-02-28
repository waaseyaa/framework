<?php

declare(strict_types=1);

namespace Aurora\CLI\Command;

use Aurora\Entity\EntityTypeManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'entity:create',
    description: 'Create a new entity of a given type',
)]
class EntityCreateCommand extends Command
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
            ->addOption('values', null, InputOption::VALUE_REQUIRED, 'JSON string of entity values', '{}');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $entityType = $input->getArgument('entity_type');
        $valuesJson = $input->getOption('values');

        /** @var array<string, mixed> $values */
        $values = json_decode($valuesJson, associative: true, flags: \JSON_THROW_ON_ERROR);

        $storage = $this->entityTypeManager->getStorage($entityType);
        $entity = $storage->create($values);
        $storage->save($entity);

        $output->writeln(sprintf('Created %s entity with ID: %s', $entityType, (string) $entity->id()));

        return Command::SUCCESS;
    }
}
