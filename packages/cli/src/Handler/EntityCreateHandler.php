<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * @api
 */
final class EntityCreateHandler
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        /** @var string $entityType */
        $entityType = $io->argument('entity_type');
        /** @var string $valuesJson */
        $valuesJson = $io->option('values') ?? '{}';

        try {
            /** @var array<string, mixed> $values */
            $values = json_decode($valuesJson, associative: true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $io->error(sprintf('Invalid JSON for --values: %s', $e->getMessage()));

            return 1;
        }

        try {
            $storage = $this->entityTypeManager->getStorage($entityType);
            $entity = $storage->create($values);
            $storage->save($entity);
        } catch (\Throwable $e) {
            $io->error(sprintf('Failed to create %s entity: %s', $entityType, $e->getMessage()));

            return 1;
        }

        $io->writeln(sprintf('Created %s entity with ID: %s', $entityType, (string) $entity->id()));

        return 0;
    }
}
