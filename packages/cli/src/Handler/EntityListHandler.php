<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * @api
 */
final class EntityListHandler
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        /** @var string $entityType */
        $entityType = $io->argument('entity_type');
        $limit = (int) ($io->option('limit') ?? 25);

        // C-22 WP2/WP3: both the query surface and the read path now live on the repository.
        $repository = $this->entityTypeManager->getRepository($entityType);
        $ids = $repository->getQuery()
            ->accessCheck(false)
            ->range(0, $limit)
            ->execute();

        if ($ids === []) {
            $io->writeln('No entities found.');

            return 0;
        }

        $entities = $repository->findMany($ids);

        $io->writeln(sprintf('%-20s %s', 'ID', 'Label'));
        $io->writeln(str_repeat('-', 40));

        foreach ($entities as $entity) {
            $io->writeln(sprintf('%-20s %s', (string) $entity->id(), $entity->label()));
        }

        return 0;
    }
}
