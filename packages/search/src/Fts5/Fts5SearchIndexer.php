<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Fts5;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Search\BatchSearchIndexerInterface;
use Waaseyaa\Search\SearchIndexableInterface;
use Waaseyaa\Search\SearchIndexerInterface;

final class Fts5SearchIndexer implements SearchIndexerInterface, BatchSearchIndexerInterface
{
    private const SCHEMA_VERSION = '2';

    private readonly LoggerInterface $logger;

    private bool $schemaReady = false;

    /**
     * @param bool $ownsProjectionFile true only for a dedicated `search.database`
     *                                 file, which sits outside schema authority;
     *                                 {@see removeAll()} then provisions it
     */
    public function __construct(
        private readonly DatabaseInterface $database,
        ?LoggerInterface $logger = null,
        private readonly bool $ownsProjectionFile = false,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function index(SearchIndexableInterface $item): void
    {
        if (!$this->schemaReady('indexing')) {
            return;
        }

        $documentId = $item->getSearchDocumentId();
        $document = $item->toSearchDocument();
        $metadata = $item->toSearchMetadata();

        $tx = $this->database->transaction();

        try {
            // FTS5 does not support INSERT OR REPLACE — delete first
            $this->deleteDocument($documentId);

            $this->database->query(
                'INSERT INTO search_index (document_id, title, body) VALUES (?, ?, ?)',
                [$documentId, $document['title'] ?? '', $document['body'] ?? ''],
            );

            $this->database->insert('search_metadata')
                ->values([
                    'document_id' => $documentId,
                    'entity_type' => $metadata['entity_type'] ?? '',
                    'content_type' => $metadata['content_type'] ?? '',
                    'source_name' => $metadata['source_name'] ?? '',
                    'quality_score' => $metadata['quality_score'] ?? 0,
                    'topics' => json_encode($metadata['topics'] ?? [], JSON_THROW_ON_ERROR),
                    'url' => $metadata['url'] ?? '',
                    'og_image' => $metadata['og_image'] ?? '',
                    'created_at' => $metadata['created_at'] ?? date('c'),
                    'schema_version' => self::SCHEMA_VERSION,
                ])
                ->execute();

            $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollBack();
            throw $e;
        }
    }

    public function reindexBatch(iterable $items): int
    {
        if (!$this->schemaReady('batch reindexing')) {
            return 0;
        }

        $tx = $this->database->transaction();
        $count = 0;

        try {
            foreach ($items as $item) {
                $documentId = $item->getSearchDocumentId();
                $document = $item->toSearchDocument();
                $metadata = $item->toSearchMetadata();

                // No deleteDocument(): search:reindex clears the index up front,
                // so every document in a reindex batch is a fresh insert and one
                // transaction wraps the whole chunk, not one per document.
                $this->database->query(
                    'INSERT INTO search_index (document_id, title, body) VALUES (?, ?, ?)',
                    [$documentId, $document['title'] ?? '', $document['body'] ?? ''],
                );

                $this->database->insert('search_metadata')
                    ->values([
                        'document_id' => $documentId,
                        'entity_type' => $metadata['entity_type'] ?? '',
                        'content_type' => $metadata['content_type'] ?? '',
                        'source_name' => $metadata['source_name'] ?? '',
                        'quality_score' => $metadata['quality_score'] ?? 0,
                        'topics' => json_encode($metadata['topics'] ?? [], JSON_THROW_ON_ERROR),
                        'url' => $metadata['url'] ?? '',
                        'og_image' => $metadata['og_image'] ?? '',
                        'created_at' => $metadata['created_at'] ?? date('c'),
                        'schema_version' => self::SCHEMA_VERSION,
                    ])
                    ->execute();

                $count++;
            }

            $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollBack();
            throw $e;
        }

        return $count;
    }

    public function remove(string $documentId): void
    {
        if (!$this->schemaReady('removal')) {
            return;
        }

        $tx = $this->database->transaction();

        try {
            $this->deleteDocument($documentId);
            $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollBack();
            throw $e;
        }
    }

    /**
     * Empty the projection before a `search:reindex` rebuild.
     *
     * This is the only method that may provision schema, and only on a
     * dedicated projection file. On the authoritative database the
     * `waaseyaa/search` migration owns the schema, so a missing projection is
     * refused with `[SEARCH-DB002]` rather than created.
     */
    public function removeAll(): void
    {
        if ($this->ownsProjectionFile) {
            if (!$this->database instanceof DBALDatabase) {
                throw new \LogicException('A dedicated search projection file must be a DBALDatabase.');
            }
            Fts5SearchSchema::install($this->database->getConnection());
            $this->schemaReady = true;
        } elseif (!$this->tablesExist()) {
            throw new \RuntimeException('[SEARCH-DB002] The search projection tables do not exist on the application database. Run `migrate` to apply the waaseyaa/search migration, then `search:reindex`.');
        }

        $tx = $this->database->transaction();

        try {
            $this->database->query('DELETE FROM search_index');
            $this->database->delete('search_metadata')->execute();
            $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollBack();
            throw $e;
        }
    }

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Read-only readiness check for the serving paths. A missing projection is
     * skipped and logged, never created. Only a positive result is cached, so
     * a migration applied later is picked up.
     */
    private function schemaReady(string $operation): bool
    {
        if ($this->schemaReady || $this->schemaReady = $this->tablesExist()) {
            return true;
        }

        $this->logger->warning(sprintf(
            'Search %s skipped: the search projection tables do not exist. %s',
            $operation,
            $this->ownsProjectionFile
                ? 'Run `search:reindex` to provision the dedicated search database.'
                : 'Run `migrate` to apply the waaseyaa/search migration.',
        ));

        return false;
    }

    private function tablesExist(): bool
    {
        $rows = iterator_to_array($this->database->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('search_index', 'search_metadata')",
        ));

        return count($rows) === 2;
    }

    private function deleteDocument(string $documentId): void
    {
        $this->database->query('DELETE FROM search_index WHERE document_id = ?', [$documentId]);
        $this->database->delete('search_metadata')
            ->condition('document_id', $documentId)
            ->execute();
    }
}
