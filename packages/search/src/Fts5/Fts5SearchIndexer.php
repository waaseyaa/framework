<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Fts5;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Search\SearchIndexableInterface;
use Waaseyaa\Search\SearchIndexerInterface;

final class Fts5SearchIndexer implements SearchIndexerInterface
{
    private const SCHEMA_VERSION = '1';

    public function __construct(
        private readonly DatabaseInterface $database,
    ) {}

    public function ensureSchema(): void
    {
        $this->database->query(<<<'SQL'
            CREATE VIRTUAL TABLE IF NOT EXISTS search_index USING fts5(
                document_id UNINDEXED,
                title,
                body,
                tokenize='porter unicode61'
            )
        SQL);

        $this->database->query(<<<'SQL'
            CREATE TABLE IF NOT EXISTS search_metadata (
                document_id TEXT PRIMARY KEY,
                entity_type TEXT NOT NULL,
                content_type TEXT NOT NULL DEFAULT '',
                source_name TEXT NOT NULL DEFAULT '',
                quality_score INTEGER NOT NULL DEFAULT 0,
                topics TEXT NOT NULL DEFAULT '[]',
                url TEXT NOT NULL DEFAULT '',
                og_image TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL,
                schema_version TEXT NOT NULL
            )
        SQL);

        $this->database->query('CREATE INDEX IF NOT EXISTS idx_search_meta_entity_type ON search_metadata(entity_type)');
        $this->database->query('CREATE INDEX IF NOT EXISTS idx_search_meta_content_type ON search_metadata(content_type)');
        $this->database->query('CREATE INDEX IF NOT EXISTS idx_search_meta_source ON search_metadata(source_name)');
    }

    public function index(SearchIndexableInterface $item): void
    {
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

    public function remove(string $documentId): void
    {
        $tx = $this->database->transaction();

        try {
            $this->deleteDocument($documentId);
            $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollBack();
            throw $e;
        }
    }

    public function removeAll(): void
    {
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

    private function deleteDocument(string $documentId): void
    {
        $this->database->query('DELETE FROM search_index WHERE document_id = ?', [$documentId]);
        $this->database->delete('search_metadata')
            ->condition('document_id', $documentId)
            ->execute();
    }
}
