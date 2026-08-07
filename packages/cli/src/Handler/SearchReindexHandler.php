<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Search\BatchSearchIndexerInterface;
use Waaseyaa\Search\Projection\EntitySearchProjectionRegistry;
use Waaseyaa\Search\SearchIndexableInterface;
use Waaseyaa\Search\SearchIndexerInterface;

/**
 * Rebuilds the search index from every indexable document.
 *
 * #2270: entities are resolved through the shared
 * {@see EntitySearchProjectionRegistry} — self-indexable entities pass
 * through unchanged, ordinary content entities (Node) are projected by the
 * registered projectors, and everything else is skipped. This is the same
 * projection contract the lifecycle subscriber and query-time candidate
 * resolution use, so a full reindex cannot drift from incremental indexing.
 *
 * @api
 */
final class SearchReindexHandler
{
    private const BATCH_SIZE = 100;

    private readonly EntitySearchProjectionRegistry $projectionRegistry;

    public function __construct(
        private readonly SearchIndexerInterface $indexer,
        private readonly EntityTypeManagerInterface $entityTypeManager,
        ?EntitySearchProjectionRegistry $projectionRegistry = null,
    ) {
        $this->projectionRegistry = $projectionRegistry ?? new EntitySearchProjectionRegistry([]);
    }

    public function execute(SymfonyCommandIO $io): int
    {
        $batchSize = (int) ($io->option('batch-size') ?? self::BATCH_SIZE);
        if ($batchSize < 1) {
            $batchSize = self::BATCH_SIZE;
        }

        // Clear the whole index once up front. Because the index is now empty,
        // each batch is a pure insert: the per-document delete-first that
        // index() performs (FTS5 has no INSERT OR REPLACE) is redundant work
        // for a full reindex, so we hand whole chunks to reindexBatch() when the
        // indexer supports it.
        $io->writeln('Clearing search index...');
        $this->indexer->removeAll();

        $batchIndexer = $this->indexer instanceof BatchSearchIndexerInterface ? $this->indexer : null;

        $totalIndexed = 0;
        $totalProjectionFailures = 0;

        foreach ($this->entityTypeManager->getDefinitions() as $entityType) {
            // C-22 WP2/WP3: both the query surface and the read path now live on the repository.
            $repository = $this->entityTypeManager->getRepository($entityType->id());
            $typeIndexed = 0;
            $offset = 0;

            // Stream IDs in pages instead of findMany() with no args, which
            // would pull every entity of every type into memory at once.
            while (true) {
                // System reindex enumerates every indexable document and runs
                // without a request-scoped account; entity-level access is
                // irrelevant to building the index (the search provider gates
                // reads). Mirrors SitemapGenerator's system-context bypass, and
                // accessCheck(false) keeps the unbound-getQuery() gate satisfied.
                $ids = $repository->getQuery()
                    ->accessCheck(false)
                    ->range($offset, $batchSize)
                    ->execute();

                if ($ids === []) {
                    break;
                }

                $offset += count($ids);

                $indexables = [];
                $projectionFailures = 0;
                foreach ($repository->findMany($ids) as $entity) {
                    try {
                        $indexable = $this->resolveIndexable($entity);
                    } catch (\Throwable) {
                        // Fail soft per entity, content-free: one unprojectable
                        // entity must not abort the whole reindex or leak its
                        // values into operator output.
                        ++$projectionFailures;
                        continue;
                    }
                    if ($indexable !== null) {
                        $indexables[] = $indexable;
                    }
                }
                if ($projectionFailures > 0) {
                    $totalProjectionFailures += $projectionFailures;
                    $io->writeln("  [{$entityType->id()}] Skipped $projectionFailures entities whose search projection failed.");
                }

                if ($indexables !== []) {
                    if ($batchIndexer !== null) {
                        // One transaction for the whole chunk, no per-document delete.
                        $typeIndexed += $batchIndexer->reindexBatch($indexables);
                    } else {
                        foreach ($indexables as $indexable) {
                            $this->indexer->index($indexable);
                            $typeIndexed++;
                        }
                    }

                    $io->writeln("  [{$entityType->id()}] Indexed $typeIndexed entities...");
                }

                // A short page means we reached the end of this type.
                if (count($ids) < $batchSize) {
                    break;
                }
            }

            $totalIndexed += $typeIndexed;

            if ($typeIndexed > 0) {
                $io->writeln("{$entityType->id()}: indexed $typeIndexed entities");
            }
        }

        $io->writeln("Reindex complete. $totalIndexed documents indexed.");
        $io->writeln('Schema version: ' . $this->indexer->getSchemaVersion());

        if ($totalProjectionFailures > 0) {
            $io->writeln("Reindex failed: $totalProjectionFailures entities could not be projected.");
            return 1;
        }

        return 0;
    }

    private function resolveIndexable(EntityInterface $entity): ?SearchIndexableInterface
    {
        return $this->projectionRegistry->resolveIndexable($entity);
    }
}
