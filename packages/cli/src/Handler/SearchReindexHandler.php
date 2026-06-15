<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\CliIO;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Search\BatchSearchIndexerInterface;
use Waaseyaa\Search\SearchIndexableInterface;
use Waaseyaa\Search\SearchIndexerInterface;

/**
 * @api
 */
final class SearchReindexHandler
{
    private const BATCH_SIZE = 100;

    public function __construct(
        private readonly SearchIndexerInterface $indexer,
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    public function execute(CliIO $io): int
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

        foreach ($this->entityTypeManager->getDefinitions() as $entityType) {
            $storage = $this->entityTypeManager->getStorage($entityType->id());
            $typeIndexed = 0;
            $offset = 0;

            // Stream IDs in pages instead of loadMultiple() with no args, which
            // would pull every entity of every type into memory at once.
            while (true) {
                // System reindex enumerates every indexable document and runs
                // without a request-scoped account; entity-level access is
                // irrelevant to building the index (the search provider gates
                // reads). Mirrors SitemapGenerator's system-context bypass, and
                // accessCheck(false) keeps the unbound-getQuery() gate satisfied.
                $ids = $storage->getQuery()
                    ->accessCheck(false)
                    ->range($offset, $batchSize)
                    ->execute();

                if ($ids === []) {
                    break;
                }

                $offset += count($ids);

                $indexables = [];
                foreach ($storage->loadMultiple($ids) as $entity) {
                    if ($entity instanceof SearchIndexableInterface) {
                        $indexables[] = $entity;
                    }
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

        return 0;
    }
}
