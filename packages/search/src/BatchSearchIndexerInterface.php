<?php

declare(strict_types=1);

namespace Waaseyaa\Search;

/**
 * Optional capability: index many items in a single transaction.
 *
 * A full reindex (search:reindex) clears the index once up front and then
 * re-adds every indexable document. Routing each document through
 * {@see SearchIndexerInterface::index()} pays for one transaction *and* one
 * redundant delete-first per document (index() upserts by deleting before
 * insert). This contract lets a reindex hand a whole chunk to the indexer so
 * the implementation can (a) wrap the chunk in ONE transaction and (b) skip the
 * per-document delete — the caller guarantees the documents are not already
 * present (a freshly-cleared index).
 *
 * Indexers that cannot honour these guarantees simply do not implement this
 * interface; callers fall back to per-document
 * {@see SearchIndexerInterface::index()}.
 *
 * @api
 */
interface BatchSearchIndexerInterface
{
    /**
     * Add many documents to the index in a single transaction, skipping the
     * per-document delete-first that {@see SearchIndexerInterface::index()}
     * performs.
     *
     * The caller MUST guarantee the index is empty of these document IDs (for
     * example after {@see SearchIndexerInterface::removeAll()}). Behaviour when a
     * document ID already exists is undefined and may violate a UNIQUE / primary
     * key constraint — use {@see SearchIndexerInterface::index()} for upserts.
     *
     * @param iterable<SearchIndexableInterface> $items
     *
     * @return int Number of documents written.
     */
    public function reindexBatch(iterable $items): int;
}
